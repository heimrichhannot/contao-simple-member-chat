#!/usr/bin/env bash
set -euo pipefail
base=https://contao0507.contao.hhdev
chat_uuid=01a0ae9d-2c0d-7b36-8209-a415ae1e4ed5
foreign_uuid=01a0ae9e-4c90-7a3e-a0e8-ad8caacf4c85
out=$(mktemp -d /tmp/member-chat-http.XXXXXX)
chmod 700 "$out"
trap 'rm -f "$out"/*.cookies "$out"/token "$out"/login-data' EXIT
request() {
    local name=$1 expected=$2
    shift 2
    curl -ksS -D "$out/$name.headers" -o "$out/$name.html" "$@"
    local status
    status=$(awk 'NR==1 {print $2}' "$out/$name.headers")
    printf '%s: HTTP %s\n' "$name" "$status"
    test "$status" = "$expected"
}
extract_token() {
    python3 - "$1" "$out/token" <<'PY'
import sys, re, html
from pathlib import Path
value = re.search(r'name="REQUEST_TOKEN" value="([^"]+)"', Path(sys.argv[1]).read_text())[1]
Path(sys.argv[2]).write_text(html.unescape(value))
PY
}
for member in alice bob; do
    request "$member-login-form" 200 -c "$out/$member.cookies" "$base/phase3a-chat-legacy.html"
    python3 - "$out/$member-login-form.html" "$member" "$out/login-data" <<'PY'
import sys, re, html, urllib.parse
from pathlib import Path
source = Path(sys.argv[1]).read_text()
fields = dict((k, html.unescape(v)) for k,v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', source))
fields.update(username='phase3a-chat-'+sys.argv[2], password='Phase3a-demo-only-2026!')
Path(sys.argv[3]).write_text(urllib.parse.urlencode(fields))
PY
    request "$member-login" 302 -b "$out/$member.cookies" -c "$out/$member.cookies" --data-binary "@$out/login-data" "$base/phase3a-chat-legacy.html"
done
request anonymous 401 "$base/_member_chat/conversations?page=86"
request search 200 -b "$out/alice.cookies" "$base/_member_chat/contacts?page=86&q=Chat"
extract_token "$out/search.html"
request open 303 -b "$out/alice.cookies" --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86&member=10' "$base/_member_chat/conversations"
request legacy 200 -b "$out/alice.cookies" "$base/phase3a-chat-legacy/$chat_uuid.html"
request modern 200 -b "$out/alice.cookies" "$base/phase3a-chat-modern/$chat_uuid.html"
request list 200 -b "$out/alice.cookies" "$base/_member_chat/conversations?page=86"
request messages 200 -b "$out/alice.cookies" "$base/_member_chat/conversations/$chat_uuid/messages?page=86"
request compose 200 -b "$out/alice.cookies" "$base/_member_chat/conversations/$chat_uuid/compose?page=86"
extract_token "$out/compose.html"
request send 200 -b "$out/alice.cookies" --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86' --data-urlencode 'text=Phase 3a verification https://example.org/?a=1&b=2 <script>alert(1)</script>' "$base/_member_chat/conversations/$chat_uuid/messages"
request invalid-message 422 -b "$out/alice.cookies" --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86&text=%20%20' "$base/_member_chat/conversations/$chat_uuid/messages"
request invalid-csrf 400 -b "$out/alice.cookies" --data 'page=86&text=Rejected&REQUEST_TOKEN=invalid' "$base/_member_chat/conversations/$chat_uuid/messages"
request incremental 200 -b "$out/bob.cookies" -H 'Accept: text/vnd.turbo-stream.html' "$base/_member_chat/conversations/$chat_uuid/messages?page=86&after=0"
after=$(awk 'tolower($1)=="x-chat-after:" {gsub("\r", "", $2); print $2}' "$out/incremental.headers")
request empty-poll 204 -b "$out/bob.cookies" -H 'Accept: text/vnd.turbo-stream.html' "$base/_member_chat/conversations/$chat_uuid/messages?page=86&after=$after"
request list-poll 200 -b "$out/alice.cookies" -H 'Accept: text/vnd.turbo-stream.html' "$base/_member_chat/conversations?page=86&since=0"
request foreign 404 -b "$out/alice.cookies" "$base/_member_chat/conversations/$foreign_uuid/messages?page=86"
request malformed 404 -b "$out/alice.cookies" "$base/phase3a-chat-legacy/not-a-uuid.html"
python3 - "$out" <<'PY'
from pathlib import Path
import sys, re
p=Path(sys.argv[1])
for layout in ['legacy','modern']:
    source=(p/(layout+'.html')).read_text()
    head=source.split('</head>')[0]
    assert head.count('name="turbo-cache-control" content="no-cache"') == 1
    assert 'huh_member_chat' in source
    for frame in ['chat-search','chat-conversations','chat-messages','chat-compose']:
        assert 'id="'+frame+'"' in source
    print(layout+': head meta, Encore asset and all four frames verified')
for name in ['anonymous','list','messages','compose','search','send','incremental','empty-poll','list-poll','foreign','invalid-csrf']:
    headers=(p/(name+'.headers')).read_text().lower()
    assert 'no-store' in headers and 'private' in headers and 'vary: accept' in headers
body=(p/'send.html').read_text()
assert 'action="append" target="chat-messages"' in body
assert 'action="replace" target="chat-compose"' in body
assert '&lt;script&gt;' in body and '<script>alert' not in body
assert 'rel="noopener nofollow" target="_blank"' in body
assert 'text/vnd.turbo-stream.html' in (p/'send.headers').read_text()
assert re.search(r'<textarea[^>]*></textarea>', body)
assert re.search(r'<textarea[^>]*>  </textarea>', (p/'invalid-message.html').read_text())
assert (p/'empty-poll.html').stat().st_size == 0
print('Headers, escaping, form reset, error text preservation and empty poll verified')
print('Responses saved to '+str(p))
PY
