const Encore = require('/var/www/html/node_modules/@symfony/webpack-encore')
Encore.configureRuntimeEnvironment('dev')
require('/var/www/html/webpack.config.js')
Encore.addEntry('huh_member_chat', '/home/dev/Kunden/github/contao-simple-member-chat/assets/js/member_chat_entry.js')
Encore.addEntry('huh_member_chat_badge', '/home/dev/Kunden/github/contao-simple-member-chat/assets/js/member_chat.js')
const config = Encore.getWebpackConfig()
config.resolve.symlinks = false
module.exports = config
