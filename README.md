# KyPanel for Clientexec

Version 1.0.0 · Clientexec 6 and later · PHP 7.4+

Server plugin for selling hosting accounts on KyPanel servers.

## Install

1. Copy `plugins/server/kypanel` to the same folder in your Clientexec.
2. In KyPanel, open Ky Admin › Development › Manage API Tokens and create a token with
   **accounts** (read and change) and **packages** (read). Limit it to the IP of your Clientexec server.
3. In Clientexec, go to Settings › Products › Servers, add a server with the plugin **KyPanel**,
   the hostname, the token and port 7711. Click Test Connection.
4. On each hosting product, set **Name on server** to the KyPanel package name.

Creating, suspending, unsuspending, deleting, password and package changes run on the server.
Clients get a "Login to KyPanel" link that signs them in with a one-time link; reseller
packages also get "Login to Ky Admin".

License: MIT

Documentation: https://docs.kypanel.com/ · Support: https://my.kypanel.com/tickets/new
