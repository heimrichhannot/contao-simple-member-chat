#!/usr/bin/env bash
set -euo pipefail
base=${CHAT_BASE_URL:-https://127.0.0.1:32773}
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
    [[ "|$expected|" == *"|$status|"* ]]
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
request badge-anonymous 401 "$base/_member_chat/unread"
request badge 200 -b "$out/alice.cookies" -H 'Accept-Language: de' "$base/_member_chat/unread?_locale=en"
request backend-smoke 302 "$base/contao?do=member_chat"
request anonymous 401 "$base/_member_chat/conversations?page=86"
request search 200 -b "$out/alice.cookies" "$base/_member_chat/contacts?page=86&q=Chat"
extract_token "$out/search.html"
request open 303 -b "$out/alice.cookies" --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86&member=10' "$base/_member_chat/conversations"
request legacy 200 -b "$out/alice.cookies" "$base/phase3a-chat-legacy/$chat_uuid.html"
request modern 200 -b "$out/alice.cookies" "$base/phase3a-chat-modern/$chat_uuid.html"
request list 200 -b "$out/alice.cookies" "$base/_member_chat/conversations?page=86"
initial_cursor=$(python3 - "$out/list.html" <<'PYCODE'
from pathlib import Path
import re, sys
source = Path(sys.argv[1]).read_text()
print('since=' + re.search(r'data-chat-since="([^"]+)"', source)[1] + '&fingerprint=' + re.search(r'data-chat-fingerprint="([^"]+)"', source)[1])
PYCODE
)
request initial-idle-list 204 -b "$out/alice.cookies" "$base/_member_chat/conversations?page=86&$initial_cursor"
request messages 200 -b "$out/alice.cookies" "$base/_member_chat/conversations/$chat_uuid/messages?page=86"
request compose 200 -b "$out/alice.cookies" "$base/_member_chat/conversations/$chat_uuid/compose?page=86"
extract_token "$out/compose.html"
request send 200 -H 'Accept-Language: de' -b "$out/alice.cookies" --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86' --data-urlencode 'text=Phase 3a verification https://example.org/?a=1&b=2 <script>alert(1)</script>' "$base/_member_chat/conversations/$chat_uuid/messages"
request invalid-message 422 -b "$out/alice.cookies" --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86&text=%20%20' "$base/_member_chat/conversations/$chat_uuid/messages"
request invalid-csrf 400 -b "$out/alice.cookies" --data 'page=86&text=Rejected&REQUEST_TOKEN=invalid' "$base/_member_chat/conversations/$chat_uuid/messages"
request incremental 200 -b "$out/bob.cookies" -H 'Accept: text/vnd.turbo-stream.html' "$base/_member_chat/conversations/$chat_uuid/messages?page=86&after=0"
after=$(awk 'tolower($1)=="x-chat-after:" {gsub("\r", "", $2); print $2}' "$out/incremental.headers")
# Drain the earliest unseen windows before asserting an idle response.
while [ "$(awk 'tolower($1)=="x-chat-count:" {gsub("\r", "", $2); print $2}' "$out/incremental.headers")" = 50 ]; do
    request incremental '200|204' -b "$out/bob.cookies" -H 'Accept: text/vnd.turbo-stream.html' "$base/_member_chat/conversations/$chat_uuid/messages?page=86&after=$after"
    if [ ! -s "$out/incremental.html" ]; then break; fi
    after=$(awk 'tolower($1)=="x-chat-after:" {gsub("\r", "", $2); print $2}' "$out/incremental.headers")
done
request empty-poll 204 -b "$out/bob.cookies" -H 'Accept: text/vnd.turbo-stream.html' "$base/_member_chat/conversations/$chat_uuid/messages?page=86&after=$after"
request list-poll 200 -b "$out/alice.cookies" -H 'Accept: text/vnd.turbo-stream.html' "$base/_member_chat/conversations?page=86&since=0"
request foreign 404 -b "$out/alice.cookies" "$base/_member_chat/conversations/$foreign_uuid/messages?page=86"
request malformed 404 -b "$out/alice.cookies" "$base/phase3a-chat-legacy/not-a-uuid.html"
# Phase 3b: language must come from the English page, including action streams.
request locale 200 -b "$out/alice.cookies" -H 'Accept-Language: de' "$base/_member_chat/conversations/$chat_uuid/compose?page=86"
request word-search 200 -b "$out/alice.cookies" "$base/_member_chat/contacts?page=86&q=Car"
request no-contacts 200 -b "$out/alice.cookies" -H 'Accept-Language: de' "$base/_member_chat/contacts?page=86&q=zzzznomatch"
request short-search 200 -b "$out/alice.cookies" "$base/_member_chat/contacts?page=86&q=C"
request idle-list-one 200 -b "$out/bob.cookies" "$base/_member_chat/conversations?page=86&since=0"
idle_since=$(awk 'tolower($1)=="x-chat-since:" {gsub("\r", "", $2); print $2}' "$out/idle-list-one.headers")
idle_fingerprint=$(awk 'tolower($1)=="x-chat-fingerprint:" {gsub("\r", "", $2); print $2}' "$out/idle-list-one.headers")
sleep 2
request idle-message 204 -b "$out/bob.cookies" "$base/_member_chat/conversations/$chat_uuid/messages?page=86&after=$after"
request idle-list-two 204 -b "$out/bob.cookies" "$base/_member_chat/conversations?page=86&since=$idle_since&fingerprint=$idle_fingerprint"
request older-messages 200 -b "$out/bob.cookies" "$base/_member_chat/conversations/$chat_uuid/messages?page=86&before=$after"
request mixed-messages 400 -b "$out/bob.cookies" "$base/_member_chat/conversations/$chat_uuid/messages?page=86&before=$after&after=0"
before=$(python3 - "$out/list.html" <<'PYCODE'
from pathlib import Path
import re, sys, html
print(html.unescape(re.search(r'data-chat-before="([^"]+)"', Path(sys.argv[1]).read_text())[1]))
PYCODE
)
request older-conversations 200 -b "$out/alice.cookies" "$base/_member_chat/conversations?page=86&before=$before"
request mixed-conversations 400 -b "$out/alice.cookies" "$base/_member_chat/conversations?page=86&before=$before&since=0"
request mute 200 -b "$out/alice.cookies" -H 'Accept-Language: de' --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86&muted=1' "$base/_member_chat/conversations/$chat_uuid/mute"
request muted-list 200 -b "$out/alice.cookies" "$base/_member_chat/conversations?page=86&since=0"
extract_token "$out/mute.html"
request mute-invalid 400 -b "$out/alice.cookies" --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86&muted=2' "$base/_member_chat/conversations/$chat_uuid/mute"
request mute-csrf 400 -b "$out/alice.cookies" --data 'page=86&muted=1&REQUEST_TOKEN=invalid' "$base/_member_chat/conversations/$chat_uuid/mute"
request mute-foreign 404 -b "$out/alice.cookies" --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86&muted=1' "$base/_member_chat/conversations/$foreign_uuid/mute"
request unmute 200 -b "$out/alice.cookies" -H 'Accept-Language: de' --data-urlencode "REQUEST_TOKEN@$out/token" --data 'page=86&muted=0' "$base/_member_chat/conversations/$chat_uuid/mute"
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
for name in ['badge-anonymous','badge','anonymous','list','messages','compose','search','send','incremental','empty-poll','list-poll','foreign','invalid-csrf']:
    headers=(p/(name+'.headers')).read_text().lower()
    assert 'no-store' in headers and 'private' in headers and 'vary: accept' in headers
badge=(p/'badge.html').read_text()
assert 'id="chat-unread"' in badge and 'aria-live="off"' in badge
assert 'data-chat-poll="badge"' in badge and 'data-chat-mode="full"' in badge
assert 'data-chat-poll-interval="30000"' in badge and 'data-chat-url=' in badge
assert ' src=' not in badge and 'lang="en"' in badge
assert 'contao/login' in (p/'backend-smoke.headers').read_text()
print('Badge polling markup, locale, privacy and backend authentication boundary verified')
body=(p/'send.html').read_text()
assert 'action="append" target="chat-messages"' in body
assert 'action="replace" target="chat-compose"' in body
assert '&lt;script&gt;' in body and '<script>alert' not in body
assert 'rel="noopener nofollow" target="_blank"' in body
assert 'text/vnd.turbo-stream.html' in (p/'send.headers').read_text()
assert re.search(r'<textarea[^>]*></textarea>', body)
assert '>Send</button>' in body
assert re.search(r'<textarea[^>]*>  </textarea>', (p/'invalid-message.html').read_text())
assert (p/'empty-poll.html').stat().st_size == 0
# Turbo rejects a frame response whose <turbo-frame> src references the request URL.
for name in ['list','messages']:
    frame=re.search(r'<turbo-frame[^>]*>', (p/(name+'.html')).read_text()).group(0)
    assert ' src=' not in frame and ' loading=' not in frame, name+' frame response must not carry src'
for layout in ['legacy','modern']:
    source=(p/(layout+'.html')).read_text()
    assert re.search(r'<turbo-frame[^>]*id="chat-messages"[^>]*\ssrc=', source), layout+' page must embed messages frame with src'
assert '>Send</button>' in (p/'locale.html').read_text()
assert 'Chat Carol' in (p/'word-search.html').read_text()
assert 'No contacts found.' in (p/'no-contacts.html').read_text()
assert 'No contacts found.' not in (p/'short-search.html').read_text()
def header(name, key):
    return re.search(r'^'+key+r':(.*)$', (p/(name+'.headers')).read_text(), re.M|re.I)[1].strip()
assert header('idle-list-one', 'x-chat-since') == header('idle-list-two', 'x-chat-since')
assert (p/'idle-list-two.html').stat().st_size == 0
assert header('idle-list-one', 'x-chat-fingerprint') == header('idle-list-two', 'x-chat-fingerprint')
print('Stable idle X-Chat-Since: '+header('idle-list-two', 'x-chat-since'))
for name, action, target in [('older-messages','prepend','chat-messages'),('older-conversations','append','chat-conversations')]:
    source=(p/(name+'.html')).read_text()
    assert 'action="'+action+'" target="'+target+'"' in source
    assert 'x-chat-before:' in (p/(name+'.headers')).read_text().lower()
    assert 'x-chat-after:' not in (p/(name+'.headers')).read_text().lower()
    assert 'x-chat-since:' not in (p/(name+'.headers')).read_text().lower()
    assert ('data-chat-message-id' if target == 'chat-messages' else 'data-chat-uuid') in source
assert 'role="separator"' in (p/'older-messages.html').read_text()
for name, pressed in [('mute','true'),('unmute','false')]:
    source=(p/(name+'.html')).read_text()
    assert 'action="replace" target="chat-mute"' in source
    assert 'aria-pressed="'+pressed+'"' in source
    assert ('>Unmute conversation</button>' if pressed == 'true' else '>Mute conversation</button>') in source
    assert 'data-chat-poll' not in source and ' src=' not in source
assert 'aria-label="Muted"' in (p/'muted-list.html').read_text()
assert 'aria-describedby="chat-compose-error"' in (p/'invalid-message.html').read_text()
print('Locale, word search, before windows, mute/CSRF/access and error association verified')
print('Frame responses carry no self-referencing src')
print('Headers, escaping, form reset, error text preservation and empty poll verified')
print('Responses saved to '+str(p))
PY
