"""
Builds the PullLens HTML user guide.

Every page shares one shell - sidebar, navigation, pager - so adding a section
means adding one entry to NAV and one write() call, and every other page picks up
the new link automatically. Run from the repository root:

    python3 docs/build-guide.py

Output goes to docs/guide/. The pages are plain HTML with a single stylesheet and
relative image paths, so they open straight from disk without a server or a build
step. Screenshots live in docs/images/guide/ and come from a demo installation
seeded with DemoDataSeeder.
"""
import os, html

OUT = 'docs/guide'
IMG = '../images/guide'

NAV = [
    ("Getting started", [
        ("index",              "Introduction"),
        ("installation",       "Installing PullLens"),
        ("first-run",          "First run & login"),
    ]),
    ("Connecting", [
        ("ai-providers",       "AI provider"),
        ("github",             "Connecting GitHub"),
        ("repositories",       "Adding repositories"),
        ("repository-settings","Repository settings"),
    ]),
    ("Using PullLens", [
        ("dashboard",          "Dashboard"),
        ("findings",           "Findings"),
        ("tasks",              "Tasks"),
        ("reports",            "Reports"),
        ("assistant",          "AI assistant"),
    ]),
    ("Administration", [
        ("users-roles",        "Users, roles & access"),
        ("account",            "Your account"),
        ("troubleshooting",    "Troubleshooting"),
    ]),
]

ORDER = [slug for _, items in NAV for slug, _ in items]
TITLES = {slug: title for _, items in NAV for slug, title in items}

SHELL = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} · PullLens Guide</title>
<meta name="description" content="{desc}">
<link rel="icon" href="../images/guide/favicon.png" type="image/png">
<link rel="stylesheet" href="assets/doc.css">
</head>
<body>
<div class="layout">
<aside class="sidebar">
  <a class="brand" href="index.html">
    <img src="../images/guide/favicon.png" alt="">
    <span>PullLens<small>User guide</small></span>
  </a>
  <details class="toc" open>
    <summary>Contents</summary>
    {nav}
    <a class="nav back" href="../../">&larr; Back to PullLens</a>
  </details>
</aside>
<main class="content">
  <div class="eyebrow">{eyebrow}</div>
  <h1>{h1}</h1>
  <p class="lede">{lede}</p>
  {body}
  <div class="pager">{pager}</div>
</main>
</div>
<script>
// The guide is static files on purpose, so this is the only script it carries:
// collapse the contents list on phones, where fifteen links would otherwise push
// the page itself off screen. With JS disabled the list simply stays expanded.
if (window.matchMedia('(max-width: 900px)').matches) {{
  var toc = document.querySelector('.toc');
  if (toc) {{ toc.removeAttribute('open'); }}
}}
</script>
</body>
</html>
"""

def nav_html(active):
    out = []
    for group, items in NAV:
        out.append(f'<div class="nav-group"><h4>{group}</h4>')
        for slug, title in items:
            cls = "nav active" if slug == active else "nav"
            out.append(f'<a class="{cls}" href="{slug}.html">{title}</a>')
        out.append('</div>')
    return "\n  ".join(out)

def pager_html(slug):
    i = ORDER.index(slug)
    prev_l = f'<a href="{ORDER[i-1]}.html">← {TITLES[ORDER[i-1]]}</a>' if i > 0 else '<span></span>'
    next_l = f'<a href="{ORDER[i+1]}.html">{TITLES[ORDER[i+1]]} →</a>' if i < len(ORDER)-1 else '<span></span>'
    return f'{prev_l}<span class="spacer"></span>{next_l}'

def fig(name, caption):
    return f'<figure><img src="{IMG}/{name}" alt="{html.escape(caption)}" loading="lazy"><figcaption>{caption}</figcaption></figure>'

def write(slug, eyebrow, h1, lede, body, desc=None):
    os.makedirs(OUT, exist_ok=True)
    page = SHELL.format(
        title=TITLES[slug], desc=html.escape(desc or lede), nav=nav_html(slug),
        eyebrow=eyebrow, h1=h1, lede=lede, body=body, pager=pager_html(slug),
    )
    with open(f'{OUT}/{slug}.html', 'w') as f:
        f.write(page)
    return slug

# ── Introduction ────────────────────────────────────────────────────────────
write('index', 'PullLens guide', 'What PullLens does',
  'A self-hosted AI code reviewer and engineering-visibility platform. This guide takes you from an empty server to a repository under review.',
  f"""
{fig('tour.gif', 'A walk through the dashboard, findings and delivered tasks.')}

<h2 id="what">The two questions it answers</h2>
<p>PullLens watches your Git repositories and reviews every pull request with an AI model that <em>you</em> supply the key for. From that same review it also works out what the pull request delivered, so two normally-hard questions become a page you can open:</p>
<ul>
  <li><strong>What did we merge that we shouldn't have?</strong> Security holes, race conditions, N+1 queries, missing authorization - reported on the exact lines, with a suggested fix.</li>
  <li><strong>What did everyone actually ship this month?</strong> Not commit counts. Real units of work, attributed to a developer, linked to the pull request and commits that delivered them.</li>
</ul>

<h2 id="flow">How it works</h2>
<ol class="steps">
  <li><strong>A pull request is opened or updated</strong> GitHub sends PullLens a webhook. The signature is verified before anything is processed.</li>
  <li><strong>PullLens fetches the diff</strong> Only the files worth reviewing - lock files, build output and binaries are skipped.</li>
  <li><strong>Your AI provider reviews it</strong> The diff goes to the provider you configured, using your API key. PullLens has no model of its own and no cloud service in the path.</li>
  <li><strong>Findings are posted back</strong> Inline comments on the affected lines, plus a summary review.</li>
  <li><strong>Tasks and metrics are recorded</strong> The same response yields the delivered tasks, which feed the reports.</li>
</ol>

<div class="note"><strong>Where your code goes</strong>
<p>There is no PullLens cloud. The only outbound call is to the AI provider you choose, with the key you own. If code may not leave your network at all, point PullLens at a local <a href="https://ollama.com">Ollama</a> instance - everything still works, and nothing leaves your infrastructure.</p></div>

<h2 id="map">Where to go next</h2>
<div class="cards">
  <a class="card" href="installation.html"><h4>Install PullLens →</h4><p>Requirements, one-command install, and what the installer does.</p></a>
  <a class="card" href="ai-providers.html"><h4>Connect an AI provider →</h4><p>All twelve supported providers and which model to pick.</p></a>
  <a class="card" href="github.html"><h4>Connect GitHub →</h4><p>Create the GitHub App and grant repository access.</p></a>
  <a class="card" href="repository-settings.html"><h4>Repository settings →</h4><p>Every switch, explained one by one.</p></a>
</div>

<h2 id="terms">Terms used in this guide</h2>
<table>
<tr><th>Term</th><th>Meaning</th></tr>
<tr><td><strong>Finding</strong></td><td>One problem the reviewer identified in a pull request, with a severity and a category.</td></tr>
<tr><td><strong>Task</strong></td><td>One unit of work a pull request delivered - a feature, a bug fix, a refactor.</td></tr>
<tr><td><strong>Review</strong></td><td>A single AI pass over a pull request. A pull request gets a new review each time it is updated.</td></tr>
<tr><td><strong>Provider</strong></td><td>The AI service doing the reviewing - Anthropic, OpenAI, a local Ollama, and so on.</td></tr>
<tr><td><strong>Tracked repository</strong></td><td>A repository you have told PullLens to watch.</td></tr>
</table>
""")

# ── Installation ────────────────────────────────────────────────────────────
write('installation', 'Getting started', 'Installing PullLens',
  'PullLens runs on your own machine or server. The installer handles Docker, the containers, the database and the first admin account.',
  f"""
<h2 id="requirements">Before you start</h2>
<table>
<tr><th>You need</th><th>Notes</th></tr>
<tr><td>A Linux or macOS machine</td><td>A small VPS is plenty. Two CPU cores and 2&nbsp;GB of RAM handles a normal team.</td></tr>
<tr><td>Docker</td><td>The installer offers to set it up if it is missing.</td></tr>
<tr><td>An AI provider API key</td><td>From Anthropic, OpenAI, Google and others - or none at all if you plan to run Ollama locally.</td></tr>
<tr><td>Admin access to your GitHub org</td><td>Needed once, to create the GitHub App.</td></tr>
<tr><td>A public URL <span class="pill">for webhooks</span></td><td>GitHub must be able to reach your instance to send pull request events. A tunnel works for trying it out.</td></tr>
</table>

<h2 id="install">Install</h2>
<pre><code>git clone https://github.com/Vlancy/PullLens.git
cd PullLens
./install.sh</code></pre>

<p>The installer will:</p>
<ul>
  <li>Check for Docker and offer to install it if missing</li>
  <li>Build the application, queue worker, PostgreSQL, Redis and mail containers</li>
  <li>Generate the application key and run all database migrations</li>
  <li>Create the first administrator account</li>
  <li>Print the URL and the login details</li>
</ul>

<div class="warn"><strong>Set an administrator password</strong>
<p>In production, <code>ADMIN_PASSWORD</code> must be set in <code>.env</code> before the database is seeded - PullLens refuses to invent one for you. Outside production it generates a random password and prints it once. Copy it: it is not stored anywhere else.</p></div>

<div class="warn"><strong>Serve a production instance over HTTPS</strong>
<p>A browser will not store a <code>Secure</code> cookie that arrived over plain <code>http://</code>. On an instance reached at <code>http://your-host</code> or at a bare IP address the session cookie and <code>XSRF-TOKEN</code> are dropped on every response, and the login form - along with every other form - comes back <strong>419 Page Expired</strong>.</p>
<p>The installer keeps <code>SESSION_SECURE_COOKIE</code> in step with <code>APP_URL</code>: <code>false</code> when the URL is not HTTPS, so that logging in still works, and <code>true</code> once it is. In production PullLens forces the cookie back on by itself as soon as <code>APP_URL</code> is an <code>https://</code> address, so the flag cannot be downgraded by accident. If you edit <code>.env</code> by hand, keep the two in agreement or rerun <code>./install.sh</code>.</p>
<p>A plain-HTTP instance carries its sessions in cleartext, and anyone on the network path can read one and take over the account. Treat it as a state to pass through rather than settle in: put a reverse proxy or Cloudflare terminating TLS in front of the containers - <code>X-Forwarded-Proto</code> is already trusted - and set <code>APP_URL</code> to the <code>https://</code> address.</p></div>

<h2 id="env">Settings worth reviewing</h2>
<p>These live in <code>.env</code>. The defaults in <code>.env.example</code> are production-appropriate; these are the ones most worth a second look.</p>
<table>
<tr><th>Variable</th><th>What it does</th></tr>
<tr><td><code>APP_URL</code></td><td>The public URL of your instance. Used for webhook callbacks and links in reviews. Must be correct.</td></tr>
<tr><td><code>APP_DEBUG</code></td><td>Keep <code>false</code>. Debug mode exposes stack traces and configuration to anyone who triggers an error.</td></tr>
<tr><td><code>SESSION_SECURE_COOKIE</code></td><td>Send the session cookie only over HTTPS. Set for you by the installer to match <code>APP_URL</code>, and forced on in production whenever <code>APP_URL</code> is HTTPS. Leave it <code>true</code> on a plain-HTTP instance and every login answers <strong>419 Page Expired</strong>. See <a href="#install">Install</a>.</td></tr>
<tr><td><code>REGISTRATION_ENABLED</code></td><td>Leave <code>false</code>. Accounts are created by an administrator; there is no public sign-up.</td></tr>
<tr><td><code>HOMEPAGE_LOGIN</code></td><td>Set <code>true</code> on an internal instance: <code>/</code> serves the login screen instead of the landing page, and <code>/docs</code> stops responding, so nothing is readable before sign-in. Default <code>false</code>.</td></tr>
<tr><td><code>HIDE_LOGIN</code></td><td>Takes the <strong>Log in</strong> button off the landing page while leaving <code>/login</code> working for anyone who has the address. Ignored when <code>HOMEPAGE_LOGIN</code> is on. Default <code>false</code>.</td></tr>
<tr><td><code>MATOMO_URL</code>, <code>MATOMO_SITE_ID</code></td><td>Report landing page visits to your own Matomo instance. Both are required; empty by default, and the script is never emitted on a signed-in page.</td></tr>
<tr><td><code>META_TITLE</code>, <code>META_DESCRIPTION</code>, <code>META_KEYWORDS</code></td><td>The title, description and keywords search engines and assistants index the landing page by. The defaults describe PullLens; override them on a branded instance.</td></tr>
<tr><td><code>META_IMAGE</code>, <code>META_IMAGE_ALT</code>, <code>META_LOCALE</code>, <code>META_THEME_COLOR</code></td><td>The link-preview card WhatsApp, Slack and LinkedIn render, its alt text, the Open Graph locale and the browser theme colour.</td></tr>
<tr><td><code>WEBHOOK_REQUIRE_SIGNATURE</code></td><td>Reject webhooks without a valid signature. Always enforced in production.</td></tr>
<tr><td><code>REDIS_QUEUE_RETRY_AFTER</code></td><td>Must stay above the longest job timeout (300). Lower it and a slow review can be executed twice.</td></tr>
<tr><td><code>LOG_LEVEL</code></td><td><code>warning</code> in production. <code>debug</code> is noisy and records request context.</td></tr>
<tr><td><code>TASK_TRACKER</code>, <code>TASK_TRACKER_BASE_URL</code></td><td>Turns detected issue keys such as <code>PROJ-451</code> into links to your tracker.</td></tr>
</table>

<h2 id="updating">Updating</h2>
<pre><code>./update.sh</code></pre>
<p>Pulls the latest code, rebuilds the containers and runs any new migrations. Run <code>php artisan migrate</code> after a manual upgrade if you are not using the script.</p>

<h2 id="try">Trying it without connecting anything</h2>
<p>To explore the interface with realistic data before wiring up GitHub, seed a demo dataset:</p>
<pre><code>php artisan db:seed --class=DemoDataSeeder</code></pre>
<p>This creates three fictional repositories, five fictional developers and a few dozen pull requests with reviews, findings and tasks. Every screenshot in this guide comes from that seeder. Do not run it on a real installation.</p>
""")

# ── First run ───────────────────────────────────────────────────────────────
write('first-run', 'Getting started', 'First run and login',
  'What you see the first time you open PullLens, and the two things it will ask you to set up.',
  f"""
<h2 id="login">Signing in</h2>
<p>Open the URL the installer printed. Sign in with the administrator email and password from your <code>.env</code>.</p>
<div class="note"><strong>There is no sign-up page</strong>
<p>Public registration is disabled and the route does not exist - requesting <code>/register</code> returns 404. Every account is created by an administrator from <a href="users-roles.html">Users</a>. This is deliberate: an internet-facing instance with open registration would let anyone read your findings.</p></div>

<h2 id="empty">The empty dashboard</h2>
<p>Before anything is connected, the dashboard shows two setup warnings. They disappear on their own once each piece is configured.</p>
{fig('dashboard-empty.jpg', 'A fresh installation. The two amber banners are the only things you need to act on.')}
<table>
<tr><th>Banner</th><th>What to do</th></tr>
<tr><td><em>No AI provider is configured or enabled</em></td><td>Reviews cannot run without a model. → <a href="ai-providers.html">Connect an AI provider</a></td></tr>
<tr><td><em>GitHub App is not configured</em></td><td>PullLens cannot see your repositories. → <a href="github.html">Connect GitHub</a></td></tr>
</table>

<h2 id="tour">The navigation</h2>
<table>
<tr><th>Section</th><th>What lives there</th></tr>
<tr><td><strong>Dashboard</strong></td><td>System health and headline numbers. <a href="dashboard.html">Details</a></td></tr>
<tr><td><strong>Repositories</strong></td><td>The repositories being watched, and each one's pull requests. <a href="repositories.html">Details</a></td></tr>
<tr><td><strong>Tasks</strong></td><td>Every unit of work delivered, searchable. <a href="tasks.html">Details</a></td></tr>
<tr><td><strong>Findings</strong></td><td>The backlog of problems found. <a href="findings.html">Details</a></td></tr>
<tr><td><strong>Users</strong></td><td>Accounts, roles and per-repository access. <a href="users-roles.html">Details</a></td></tr>
<tr><td><strong>Reports</strong></td><td>Eight reports covering delivery, quality and AI cost. <a href="reports.html">Details</a></td></tr>
<tr><td><strong>Assistant</strong></td><td>Ask questions about your own metrics. <a href="assistant.html">Details</a></td></tr>
<tr><td><strong>Settings</strong></td><td>Git and AI provider configuration.</td></tr>
<tr><td><strong>Monitor</strong></td><td>Horizon (queue) and Telescope (debug). Administrators only.</td></tr>
</table>

<div class="tip"><strong>Recommended order</strong>
<p>Connect the AI provider first. It takes a minute and you can verify it with the <em>Test connection</em> button before involving GitHub at all.</p></div>
""")

# ── AI providers ────────────────────────────────────────────────────────────
write('ai-providers', 'Connecting', 'Connecting an AI provider',
  'PullLens has no model of its own. You bring an API key, and you pay your provider directly at cost.',
  f"""
{fig('setup-ai-provider.gif', 'Adding a provider: choose the service, then enter the key and pick a model.')}

<h2 id="add">Adding a provider</h2>
<p>Go to <strong>Settings → AI Providers</strong> and press <strong>Add provider</strong>.</p>
{fig('ai-providers-empty.jpg', 'Settings → AI Providers before anything is configured.')}

<ol class="steps">
  <li><strong>Choose the service</strong> Twelve are supported. Each one shows a direct link to where you get an API key, and to its model list.
      {fig('ai-provider-choose.jpg', 'The provider grid. Selecting one updates the help text and the links below it.')}</li>
  <li><strong>Enter your credentials</strong> Paste the API key and pick a model. PullLens pre-selects the model it recommends for code review.
      {fig('ai-provider-credentials.jpg', 'The credentials step, with Claude Sonnet 5 pre-selected as the recommended model.')}</li>
  <li><strong>Test the connection</strong> Press <em>Test connection</em>. PullLens makes one tiny call to confirm the key works and the model is reachable, and reports the round-trip time.</li>
  <li><strong>Save</strong> The key is encrypted before it is written to the database, and is never sent back to the browser afterwards.</li>
</ol>

<h2 id="fields">What each field does</h2>
<table>
<tr><th>Field</th><th>Meaning</th></tr>
<tr><td><strong>Display name</strong></td><td>A label for your own reference. Useful when you configure more than one provider.</td></tr>
<tr><td><strong>Default model</strong></td><td>The model used for reviews unless a repository overrides it. Pre-filled with the recommended choice.</td></tr>
<tr><td><strong>API key</strong></td><td>Your provider key. Encrypted at rest. Leave blank when editing to keep the existing key.</td></tr>
<tr><td><strong>Base URL</strong> <span class="pill">optional</span></td><td>For self-hosted, proxied or gateway setups. For AWS Bedrock this field is the region instead. For Ollama it is the address of your Ollama server, e.g. <code>http://localhost:11434</code>.</td></tr>
<tr><td><strong>Enabled</strong></td><td>Turn a provider off without deleting it or losing its key.</td></tr>
<tr><td><strong>Set as default</strong></td><td>The provider used by every repository that does not override it. Exactly one provider is the default.</td></tr>
</table>

<h2 id="models">Which model should I use?</h2>
<p>Reviews run on every pull request, so the model choice drives both quality and cost. PullLens pre-selects each vendor's balanced production tier rather than its most expensive one - reviewing a diff is a bounded, single-shot task, not long agentic work, and the top tiers cost several times more per review without a matching gain.</p>
<table>
<tr><th>Provider</th><th>Recommended</th><th>Step up to</th></tr>
<tr><td>Anthropic</td><td><code>claude-sonnet-5</code></td><td><code>claude-opus-5</code>, <code>claude-fable-5</code></td></tr>
<tr><td>OpenAI</td><td><code>gpt-5.6-terra</code></td><td><code>gpt-5.6-sol</code>, <code>gpt-5.3-codex</code></td></tr>
<tr><td>Google Gemini</td><td><code>gemini-3.7-flash</code></td><td><code>gemini-3.1-pro-preview</code></td></tr>
<tr><td>DeepSeek</td><td><code>deepseek-v4-pro</code></td><td>-</td></tr>
<tr><td>Mistral</td><td><code>codestral-2508</code></td><td><code>mistral-medium-3.5</code></td></tr>
<tr><td>xAI</td><td><code>grok-4.6</code></td><td>-</td></tr>
<tr><td>Groq</td><td><code>openai/gpt-oss-120b</code></td><td>-</td></tr>
<tr><td>Cohere</td><td><code>command-a-plus-05-2026</code></td><td>-</td></tr>
<tr><td>AWS Bedrock</td><td><code>anthropic.claude-sonnet-5</code></td><td><code>anthropic.claude-opus-5</code></td></tr>
<tr><td>Ollama <span class="pill">local</span></td><td><code>qwen3-coder:30b</code></td><td><code>devstral:24b</code></td></tr>
</table>

<div class="tip"><strong>Watch what it costs</strong>
<p>Every call is logged with its token count and estimated cost. Before switching to a more expensive model, look at <a href="reports.html#ai-usage">Reports → AI Usage</a> to see what you are spending now.</p></div>

<h2 id="ollama">Keeping code entirely on your network</h2>
<p>Reviewing a diff means sending that diff to whichever provider you configure. If your policy forbids that, run <a href="https://ollama.com">Ollama</a> on your own hardware and select it as the provider. Set <strong>Base URL</strong> to your Ollama address, leave the API key blank, and nothing leaves your infrastructure - reviews, tasks and reports all work the same way.</p>

<h2 id="multiple">Using more than one provider</h2>
<p>You can configure several. One is the global default; any repository can override the provider and model in its own settings - for example a cheap local model on a low-risk internal repository, and a frontier model on the payment service.</p>
""")

# ── GitHub ──────────────────────────────────────────────────────────────────
write('github', 'Connecting', 'Connecting GitHub',
  'PullLens talks to GitHub through a GitHub App that belongs to you. It is created once, from inside PullLens, and never leaves your control.',
  f"""
<h2 id="why-app">Why a GitHub App</h2>
<p>A GitHub App is scoped to the repositories you choose, posts as its own bot identity rather than as a person, and can be revoked in one click. It is not tied to any employee's account, so nothing breaks when someone leaves.</p>

<h2 id="start">Start the setup</h2>
<p>Go to <strong>Settings → Git Providers</strong>. Before anything is connected, GitHub is listed as <em>Setup required</em>.</p>
{fig('git-providers-empty.jpg', 'Settings → Git Providers on a fresh installation.')}

<ol class="steps">
  <li><strong>Open the GitHub card</strong> Click through to start the guided setup.</li>
  <li><strong>Create the app on GitHub</strong> PullLens builds a GitHub App manifest - the name, permissions, webhook URL and secret - and hands it to GitHub. You review it on GitHub's own screen and confirm. You are choosing whether to create the app; PullLens is only filling the form in for you.
    <div class="note"><strong>Personal or organisation?</strong><p>Create the app under the organisation that owns the repositories. A personal app cannot be installed on an organisation you do not administer.</p></div></li>
  <li><strong>GitHub returns to PullLens</strong> The App ID, client credentials, private key and webhook secret come back automatically and are <strong>encrypted before they are stored</strong>. You never copy or paste them.</li>
  <li><strong>Install the app on your repositories</strong> GitHub asks which repositories the app may access - all of them, or a chosen list. This is the boundary: PullLens can only ever see what you grant here.</li>
  <li><strong>Connect the account</strong> Back in PullLens, the account appears as connected.</li>
</ol>

{fig('git-providers-connected.jpg', 'Once the app exists and an account is connected, the card shows "App configured".')}

<h2 id="permissions">What the app is allowed to do</h2>
<table>
<tr><th>Permission</th><th>Why it is needed</th></tr>
<tr><td>Pull requests - read &amp; write</td><td>Read the diff; post the review, inline comments and labels.</td></tr>
<tr><td>Contents - read</td><td>Read changed files, and the optional <code>PULLENS.md</code> calibration file.</td></tr>
<tr><td>Metadata - read</td><td>Basic repository information. Mandatory for every GitHub App.</td></tr>
<tr><td>Checks - write</td><td>Publish the review as a check run so CI can gate on it.</td></tr>
<tr><td>Issues - read &amp; write</td><td>Post and reply to pull request conversation comments.</td></tr>
</table>

<h2 id="webhooks">Webhooks</h2>
<p>GitHub notifies PullLens when a pull request is opened, updated, merged or commented on. Two things are worth knowing:</p>
<ul>
  <li><strong>Your instance must be reachable from GitHub.</strong> If reviews never start, this is almost always why. See <a href="troubleshooting.html#webhooks">Troubleshooting</a>.</li>
  <li><strong>Every delivery is signature-verified.</strong> A webhook without a valid HMAC signature is rejected with 403 - including when no secret is configured. There is no mode where unsigned deliveries are trusted in production.</li>
</ul>

<h2 id="revoking">Disconnecting</h2>
<p>Removing the connected account stops PullLens using it. Deleting the provider app removes the credentials from PullLens and uninstalls it from every account it was installed on. Your repositories and their history stay untouched on GitHub.</p>
""")

# ── Repositories ────────────────────────────────────────────────────────────
write('repositories', 'Connecting', 'Adding repositories',
  'Granting the GitHub App access is not the same as reviewing. You choose which of the available repositories PullLens actually tracks.',
  f"""
<h2 id="two-steps">Two separate gates</h2>
<table>
<tr><th>Gate</th><th>Where</th><th>Effect</th></tr>
<tr><td>App installation</td><td>On GitHub</td><td>Which repositories PullLens is <em>able</em> to see.</td></tr>
<tr><td>Tracking</td><td>In PullLens</td><td>Which of those it actually watches and reviews.</td></tr>
</table>
<p>So you can install the app organisation-wide and still track only two repositories while you evaluate it.</p>

<h2 id="add">Selecting repositories</h2>
<ol class="steps">
  <li><strong>Go to Settings → Git Providers</strong> and open the connected GitHub account.</li>
  <li><strong>Browse available repositories</strong> PullLens lists everything the app installation can reach, grouped by account or organisation.</li>
  <li><strong>Tick the ones to track</strong> and save. Each selected repository is stored with its default branch and branch list.</li>
  <li><strong>Open a pull request</strong> The first review lands within about a minute.</li>
</ol>

<div class="note"><strong>Nothing happens retroactively</strong>
<p>PullLens reviews pull requests that are opened or updated after tracking begins. To review something already open, use <em>Sync reviews</em> on the repository page.</p></div>

<h2 id="list">The repositories list</h2>
<p><strong>Repositories</strong> in the sidebar shows everything tracked, with open pull requests and finding counts. The filter switches between repositories that currently have open pull requests and all of them.</p>
{fig('repositories-empty.jpg', 'The repositories list before anything is tracked.')}

<h2 id="detail">A repository at a glance</h2>
{fig('repository-detail.jpg', 'The repository page: counts at the top, then pull requests with their review verdict and finding count.')}
<table>
<tr><th>Element</th><th>Meaning</th></tr>
<tr><td><strong>Open / Merged PRs</strong></td><td>Pull request counts by state for this repository.</td></tr>
<tr><td><strong>AI reviews</strong></td><td>How many reviews PullLens has completed here.</td></tr>
<tr><td><strong>Findings</strong></td><td>Unresolved findings. The red banner appears when any are critical or high.</td></tr>
<tr><td><strong>Pull request list</strong></td><td>Open work first, then drafts, then merged. Each row shows the author, branch, line changes, finding count and the latest verdict.</td></tr>
<tr><td><strong>Sync reviews</strong></td><td>Re-checks every open pull request and queues a review for any that lack one. Rate limited, and it respects your tracked-branch settings.</td></tr>
<tr><td><strong>Settings</strong></td><td>Per-repository configuration. <a href="repository-settings.html">Every option explained →</a></td></tr>
</table>

<h2 id="untrack">Removing a repository</h2>
<p>Untracking stops all reviewing and removes the repository and its history from PullLens. It does not touch anything on GitHub, and it does not uninstall the app - re-tracking later starts fresh.</p>
""")

# ── Repository settings ─────────────────────────────────────────────────────
write('repository-settings', 'Connecting', 'Repository settings',
  'Every switch on the repository settings screen, what it changes, and when you would want it.',
  f"""
<p>Open a repository and press <strong>Settings</strong>, or go to <strong>Settings → Git Providers → </strong> the repository. Settings apply to that repository alone.</p>

<h2 id="activity">Activity tracking</h2>
{fig('repo-settings-activity.jpg', 'Activity tracking controls what PullLens records outside of pull requests.')}
<table>
<tr><th>Setting</th><th>What it does</th><th>Default</th></tr>
<tr><td><strong>Record all push activity</strong></td><td>Records every commit pushed to any branch, not just those in pull requests. Turn this on if developers commit directly to branches and you want the effort reports to reflect that. Commits are de-duplicated by SHA, so work is never counted twice when a branch later becomes a pull request. Recording is not reviewing - pushes to unreviewed branches are counted, not commented on.</td><td>Off</td></tr>
</table>

<h2 id="reviews">Reviews</h2>
{fig('repo-settings-reviews.jpg', 'The review behaviour switches.')}
<table>
<tr><th>Setting</th><th>What it does</th><th>Default</th></tr>
<tr><td><strong>Enable reviews</strong></td><td>The master switch. Off pauses all reviewing for this repository while keeping its history and settings. Use it during a noisy migration rather than untracking.</td><td>On</td></tr>
<tr><td><strong>Auto-review on PR open</strong></td><td>Review as soon as a pull request is opened. Off means reviews only run when a new commit is pushed or you trigger one manually.</td><td>On</td></tr>
<tr><td><strong>Auto-approve and submit</strong></td><td>Submits an approving review when nothing blocking is found. Leave off unless you are confident: an approval carries weight in branch protection rules.</td><td>Off</td></tr>
<tr><td><strong>Auto-apply labels</strong></td><td>Lets PullLens add labels it suggests, such as <code>security</code> or <code>feature</code>. Only labels from a fixed vocabulary are used.</td><td>Off</td></tr>
<tr><td><strong>Auto-enhance PR titles</strong></td><td>Rewrites a title only when it carries no information - a branch name, <em>WIP</em>, <em>update</em>, or a bare ticket key. A meaningful title is never touched, and prefixes like <code>ISSUE-77-</code> or <code>feat:</code> are preserved.</td><td>Off</td></tr>
<tr><td><strong>Auto-fill empty PR descriptions</strong></td><td>Writes a description from the review walkthrough when the author left it blank. Never overwrites a description someone wrote.</td><td>On</td></tr>
<tr><td><strong>Allow replies to PR comments</strong></td><td>Lets PullLens answer follow-up questions on its findings, and evaluate a developer's claim that a finding is a false positive. Each reply costs an AI call.</td><td>Off</td></tr>
<tr><td><strong>Review language</strong></td><td>The language reviews are written in. Findings, explanations and suggested fixes all follow it.</td><td>English</td></tr>
</table>

<h2 id="merging">Merging</h2>
{fig('repo-settings-merging.jpg', 'Merge behaviour and branch selection.')}
<table>
<tr><th>Setting</th><th>What it does</th><th>Default</th></tr>
<tr><td><strong>Auto-merge approved PRs</strong></td><td>Merges automatically once the pull request is approved and all checks pass. Powerful and irreversible - most teams should leave this off.</td><td>Off</td></tr>
<tr><td><strong>Merge method</strong></td><td>Merge commit, squash or rebase, when auto-merge is on.</td><td>Merge commit</td></tr>
</table>

<h2 id="branches">Branches</h2>
<table>
<tr><th>Setting</th><th>What it does</th></tr>
<tr><td><strong>Tracked branches</strong></td><td>Only pull requests <em>targeting</em> these branches are reviewed. Leave empty to review pull requests into any branch. Use it to review work heading for <code>main</code> while ignoring long-running spike branches. Press <strong>Sync</strong> to fetch the branch list from GitHub.</td></tr>
</table>
<div class="note"><strong>Applies everywhere</strong><p>The same branch rule governs webhook-triggered reviews and the manual <em>Sync reviews</em> button, so the two cannot drift apart.</p></div>

<h2 id="engine">AI review engine</h2>
{fig('repo-settings-engine.jpg', 'Per-repository model, intensity and tone.')}
<table>
<tr><th>Setting</th><th>What it does</th><th>Default</th></tr>
<tr><td><strong>Provider</strong></td><td>Override the global default provider for this repository - a cheaper model on a low-risk repository, a stronger one on the payment service.</td><td>Global default</td></tr>
<tr><td><strong>Override model</strong></td><td>Pin a specific model rather than the provider's default.</td><td>Off</td></tr>
<tr><td><strong>Review intensity</strong></td><td><strong>Light</strong> - only critical and high findings; short summaries. Good for a legacy repository that would otherwise produce hundreds of findings.<br><strong>Balanced</strong> - all findings with useful detail. Recommended.<br><strong>Strict</strong> - includes low and informational findings, test-coverage gaps and edge cases. Best on a codebase you are actively hardening.</td><td>Balanced</td></tr>
<tr><td><strong>Review tone</strong></td><td><strong>Professional</strong> - formal and precise. <strong>Friendly</strong> - encouraging, acknowledges what went well. <strong>Concise</strong> - terse, no filler. <strong>Detailed</strong> - full explanations with impact and remediation steps.</td><td>Professional</td></tr>
<tr><td><strong>Use emoji in reviews</strong></td><td>Adds a marker such as a padlock for security. Emoji are stripped when posting if this is off, so a model that ignores the instruction still cannot add them.</td><td>Off</td></tr>
</table>

<h2 id="pullens-md">Per-repository calibration with PULLENS.md</h2>
<p>Commit a <code>PULLENS.md</code> file to the root of a repository and PullLens reads it on every review, treating it as the highest-priority instruction - above the settings above. Use it for house rules the interface cannot express:</p>
<pre><code># PullLens configuration

- This is a legacy codebase. Do not report missing type hints.
- `app/Legacy/` is frozen; ignore maintainability findings there.
- We use repository classes deliberately; do not suggest replacing them
  with direct Eloquent calls.
- Always flag anything touching `PaymentProcessor` as at least high severity.</code></pre>
""")

# ── Dashboard ───────────────────────────────────────────────────────────────
write('dashboard', 'Using PullLens', 'Dashboard',
  'Where things stand right now: what is outstanding, what is risky, and whether the system itself is healthy.',
  f"""
{fig('dashboard.jpg', 'The dashboard once repositories are being reviewed.')}
<h2 id="alerts">System alerts</h2>
<p>Banners appear only when something needs attention, and only for users who can act on it - an alert linking to a settings page you cannot open would be worse than none.</p>
<table>
<tr><th>Alert</th><th>Meaning</th></tr>
<tr><td>No AI provider configured</td><td>Reviews cannot run. <a href="ai-providers.html">Add one</a>.</td></tr>
<tr><td>AI provider returning errors</td><td>Recent reviews failed with rate limits or authentication errors. Check your key and quota.</td></tr>
<tr><td>GitHub App not configured</td><td>No repository access. <a href="github.html">Connect GitHub</a>.</td></tr>
<tr><td>GitHub authentication failing</td><td>Background jobs are getting 401s. Reconnect the account.</td></tr>
<tr><td><em>n</em> critical or high findings require attention</td><td>Unresolved high-risk findings on open pull requests. Click through to <a href="findings.html">Findings</a>.</td></tr>
</table>

<h2 id="tiles">The numbers</h2>
<table>
<tr><th>Tile</th><th>What it counts</th></tr>
<tr><td><strong>Tracked repositories</strong></td><td>Repositories PullLens is watching.</td></tr>
<tr><td><strong>Total pull requests</strong></td><td>Every pull request seen, with open and draft broken out beneath.</td></tr>
<tr><td><strong>Merged / Closed / Open / Draft PRs</strong></td><td>Counts by state.</td></tr>
<tr><td><strong>AI reviews completed</strong></td><td>Reviews finished, with the average time one takes.</td></tr>
<tr><td><strong>Total findings</strong></td><td>All findings, with the critical and high count called out.</td></tr>
</table>

<h2 id="breakdowns">Breakdowns</h2>
<table>
<tr><th>Panel</th><th>What it shows</th></tr>
<tr><td><strong>Findings by severity</strong></td><td>Critical, high, medium, low and informational.</td></tr>
<tr><td><strong>Findings by category</strong></td><td>Security, correctness, reliability, performance, maintainability and testing - where your problems actually cluster.</td></tr>
<tr><td><strong>Review verdicts</strong></td><td>Approved, commented and changes-requested, plus average review time.</td></tr>
<tr><td><strong>Recent reviews</strong></td><td>The latest completed reviews with author, verdict and finding count.</td></tr>
<tr><td><strong>Top repositories</strong></td><td>Busiest repositories by pull request volume.</td></tr>
</table>

<div class="note"><strong>Scoped to what you can see</strong>
<p>If your account is limited to certain repositories, every number here covers only those. Two people can open the same dashboard and correctly see different totals.</p></div>
""")

# ── Findings ────────────────────────────────────────────────────────────────
write('findings', 'Using PullLens', 'Findings',
  'The backlog of problems found across every repository, and how to work through it.',
  f"""
{fig('findings.jpg', 'The findings page: totals and trend at the top, filters, then the list.')}

<h2 id="what">What counts as a finding</h2>
<p>PullLens reports problems that can cause an incident or real maintenance pain - not style. It deliberately does not comment on formatting or subjective preferences: a reviewer that cries wolf gets muted, and then it catches nothing at all.</p>

<h2 id="severity">Severity</h2>
<table>
<tr><th>Severity</th><th>Means</th><th>Examples</th></tr>
<tr><td><strong>Critical</strong></td><td>Exploitable or data-destroying. Fix before merge.</td><td>SQL injection, missing authorization, leaked secret</td></tr>
<tr><td><strong>High</strong></td><td>Likely to cause an incident.</td><td>Race condition, unbounded query, unverified webhook</td></tr>
<tr><td><strong>Medium</strong></td><td>Wrong under some conditions.</td><td>Money as float, timezone assumption, missing backoff</td></tr>
<tr><td><strong>Low</strong></td><td>Worth fixing, not urgent.</td><td>Duplicated validation, missing return type</td></tr>
<tr><td><strong>Informational</strong></td><td>Advice, not a defect.</td><td>A suggestion for a future refactor</td></tr>
</table>

<h2 id="categories">Categories</h2>
<table>
<tr><th>Category</th><th>Covers</th></tr>
<tr><td><strong>Security</strong></td><td>Injection, authorization, secrets, unsafe deserialization, SSRF</td></tr>
<tr><td><strong>Correctness</strong></td><td>Logic that produces the wrong answer</td></tr>
<tr><td><strong>Reliability</strong></td><td>Crashes, unbounded growth, missing error handling</td></tr>
<tr><td><strong>Performance</strong></td><td>N+1 queries, needless work in a loop, missing indexes</td></tr>
<tr><td><strong>Maintainability</strong></td><td>Duplication and structure that will cost later</td></tr>
<tr><td><strong>Testing</strong></td><td>Untested paths, particularly ones that regressed before</td></tr>
</table>

<h2 id="filters">Filtering and searching</h2>
<table>
<tr><th>Control</th><th>Notes</th></tr>
<tr><td><strong>Search</strong></td><td>Matches finding titles. Literal - typing <code>%</code> searches for a percent sign.</td></tr>
<tr><td><strong>Repository / Developer</strong></td><td>Only values that actually have findings are offered.</td></tr>
<tr><td><strong>Severity</strong></td><td>Multi-select; combine critical and high to get a triage queue.</td></tr>
<tr><td><strong>Category</strong></td><td>One category at a time.</td></tr>
<tr><td><strong>Open / Resolved / All</strong></td><td>Defaults to open.</td></tr>
<tr><td><strong>Sort</strong></td><td>Severity (most serious first), newest, or grouped by category.</td></tr>
</table>
<div class="note"><strong>The tiles do not follow the filters</strong>
<p>Totals, categories and the trend describe the whole backlog for the selected repository and developer. Narrowing to "critical only" does not change the totals you are comparing against - otherwise the denominator would move every time you filtered.</p></div>

<h2 id="resolving">Resolving a finding</h2>
<p>Every finding is tracked until it is closed with a reason, so "we'll deal with it later" becomes a number you can see.</p>
<table>
<tr><th>Resolution</th><th>Use when</th></tr>
<tr><td><strong>Fix confirmed</strong></td><td>Fixed and verified. PullLens also sets this automatically when a later review shows the issue is gone.</td></tr>
<tr><td><strong>Fix submitted</strong></td><td>A fix is in flight but not yet merged.</td></tr>
<tr><td><strong>Acknowledged</strong></td><td>Real, accepted, deliberately not being fixed now.</td></tr>
<tr><td><strong>Won't fix</strong></td><td>Real, and a decision has been taken not to act.</td></tr>
<tr><td><strong>False positive</strong></td><td>Not actually a problem. These are excluded from developer quality scores, so a noisy reviewer does not penalise anyone.</td></tr>
</table>
<p>Select several with the checkboxes to resolve them together. Re-resolving an already-closed finding does not overwrite the original reason or timestamp.</p>

<h2 id="pr">On the pull request itself</h2>
<p>Findings are posted as inline comments on the affected lines, with an explanation and a suggested fix, plus a summary review. If <em>Allow replies</em> is enabled, a developer can reply to argue a finding is wrong and PullLens will evaluate the claim and mark it a false positive if it agrees.</p>
""")

# ── Tasks ───────────────────────────────────────────────────────────────────
write('tasks', 'Using PullLens', 'Tasks',
  'Every unit of work PullLens identified, what happened to it afterwards, and how to search the lot.',
  f"""
{fig('tasks-board.jpg', 'The tasks board, with the first-time-right rate and a "came back only" filter.')}

<h2 id="what">What a task is</h2>
<p>When PullLens reviews a pull request it also works out what that pull request <em>delivered</em> - described the way a developer would on a standup, not as a commit count. A pull request usually yields one or two tasks; one that adds an endpoint and also fixes an unrelated bug yields two.</p>
<p>Extraction happens inside the review that already runs, so tasks cost no extra AI call.</p>

<h2 id="types">Task types</h2>
<table>
<tr><th>Type</th><th>Means</th></tr>
<tr><td><strong>Feature</strong></td><td>New user-visible capability</td></tr>
<tr><td><strong>Bug fix</strong></td><td>Corrects broken behaviour</td></tr>
<tr><td><strong>Refactor</strong></td><td>Restructures without changing behaviour</td></tr>
<tr><td><strong>Performance</strong></td><td>Makes something faster or cheaper</td></tr>
<tr><td><strong>Security</strong></td><td>Closes or hardens a security gap</td></tr>
<tr><td><strong>Tests</strong></td><td>Adds or improves test coverage</td></tr>
<tr><td><strong>Documentation</strong></td><td>Written material rather than code</td></tr>
<tr><td><strong>Chore</strong></td><td>Build, config, dependencies, formatting</td></tr>
</table>

<h2 id="status">Lifecycle</h2>
<p>Status is <strong>derived from evidence</strong>, never typed in by anyone - that is what makes it trustworthy. A status somebody has to remember to update is a status that lies.</p>
<table>
<tr><th>Status</th><th>Means</th></tr>
<tr><td><strong>In progress</strong></td><td>Identified on a pull request that has not merged.</td></tr>
<tr><td><strong>Delivered</strong></td><td>Merged, and nothing has come back to it. This is the "right first time" state.</td></tr>
<tr><td><strong>Revised</strong></td><td>Later work extended or changed it.</td></tr>
<tr><td><strong>Reworked</strong></td><td>A later task had to fix a bug in it.</td></tr>
<tr><td><strong>Reverted</strong></td><td>Later work took it back out.</td></tr>
</table>
<p>When several could apply, the worst one wins - a task both extended and reverted reads as reverted.</p>

<h2 id="links">How tasks connect</h2>
<p>The reviewer is shown recent tasks from the same repository and asked whether the current work acts on any of them. Relationships are directional, and each carries the evidence for why it was drawn.</p>
<table>
<tr><th>Relation</th><th>Means</th></tr>
<tr><td><strong>Fixes</strong></td><td>Repairs a defect in earlier work. This is what marks the original <em>reworked</em>.</td></tr>
<tr><td><strong>Extends</strong></td><td>Builds on earlier work that was not broken.</td></tr>
<tr><td><strong>Reverts</strong></td><td>Removes the earlier change.</td></tr>
<tr><td><strong>Duplicates</strong></td><td>Redoes work already done.</td></tr>
<tr><td><strong>Relates to</strong></td><td>Connected, but none of the above.</td></tr>
</table>
<p>Each row shows both directions - what it fixes, and what fixed it - with the reason, so an AI-proposed link can be judged rather than taken on trust. A link a person created is marked <em>manual</em> and is never overwritten by a later review.</p>

<h2 id="metrics">The four tiles</h2>
<table>
<tr><th>Tile</th><th>Meaning</th></tr>
<tr><td><strong>Tasks</strong></td><td>How many match the current filters.</td></tr>
<tr><td><strong>First time right</strong></td><td>Share that shipped and never came back. Shows a dash rather than 0% when nothing has shipped yet.</td></tr>
<tr><td><strong>Came back</strong></td><td>How many had to be fixed or reverted.</td></tr>
<tr><td><strong>Estimated effort</strong></td><td>Summed AI effort estimates. A rough sizing signal, not a timesheet.</td></tr>
</table>

<h2 id="search">Finding things</h2>
<table>
<tr><th>Control</th><th>Notes</th></tr>
<tr><td><strong>Search</strong></td><td>Title, description and issue key. Typing <code>PROJ-451</code> finds the work for that ticket.</td></tr>
<tr><td><strong>Period</strong></td><td>Includes <em>This month</em> and <em>Last month</em> as true calendar months.</td></tr>
<tr><td><strong>Type / Status / Developer / Repository</strong></td><td>Standard filters.</td></tr>
<tr><td><strong>Came back only</strong></td><td>Just the work that needed fixing - the fastest route to a quality conversation.</td></tr>
<tr><td><strong>Click a linked task</strong></td><td>Pivots the board to everything related to it.</td></tr>
</table>

<h2 id="tracker">Issue tracker keys</h2>
<p>PullLens detects keys such as <code>PROJ-451</code> in branch names, titles and descriptions and stores them against the task. Set <code>TASK_TRACKER</code> and <code>TASK_TRACKER_BASE_URL</code> to turn them into links. Detection is conservative and skips false positives like <code>UTF-8</code>. There is no Jira integration yet - this is the key it will join on when there is.</p>

<div class="note"><strong>It starts empty and fills up</strong>
<p>Tasks are recorded from the moment the feature is deployed; history is not backfilled. The rework signal in particular needs time, because a task is only marked <em>reworked</em> when a <em>future</em> pull request fixes it.</p></div>
""")

# ── Reports ─────────────────────────────────────────────────────────────────
write('reports', 'Using PullLens', 'Reports',
  'Eight reports covering delivery, quality, effort and what the AI is costing you.',
  f"""
<div class="warn"><strong>Read this before sharing these with a team</strong>
<p>These numbers are a conversation starter, not a scoreboard. Lines of code is not productivity, and anyone measured on it will happily give you more of it. Use the reports to notice that someone's work keeps coming back and to ask <em>why</em> - the area may be under-tested, or they may have been handed the worst part of the codebase. The data tells you where to look, never what to conclude.</p></div>

<h2 id="overview">Overview</h2>
{fig('reports-overview.jpg', 'Engineering Overview - the system-wide snapshot.')}
<p>Pull request activity, code quality and system health for the selected period, filterable by developer. Defaults to today, because the overview answers "what is happening now"; every other report defaults to a longer window.</p>

<h2 id="tasks">Tasks delivered</h2>
{fig('reports-tasks.jpg', 'What each developer shipped, grouped by person.')}
<p>Per-developer delivered work for a period - the month-end report. Each developer expands to their tasks with type, effort estimate, repository, pull request and commit count. Only merged work counts: tasks on an open pull request describe intent, and counting them would let an unmerged branch inflate somebody's month.</p>

<h2 id="team">Team performance</h2>
{fig('reports-team.jpg', 'Throughput, code volume, findings and seniority per developer.')}
<table>
<tr><th>Column</th><th>Meaning</th></tr>
<tr><td><strong>PRs</strong></td><td>Opened and merged.</td></tr>
<tr><td><strong>Code</strong></td><td>Lines added and removed. Context, not achievement.</td></tr>
<tr><td><strong>Avg effort/PR</strong></td><td>The AI's effort estimate averaged across their pull requests.</td></tr>
<tr><td><strong>Avg 1st review</strong></td><td>How long a pull request waits before its first review.</td></tr>
<tr><td><strong>Findings</strong></td><td>Findings on their work by severity, and the share fixed.</td></tr>
<tr><td><strong>Seniority</strong></td><td>A weighted score: 60% severity-weighted finding rate, 25% fix rate, 15% review verdicts. Shown only after at least three reviewed pull requests - below that it would swing wildly and mean nothing. False positives are excluded.</td></tr>
</table>

<h2 id="repos">Repository health</h2>
{fig('reports-repos.jpg', 'Per-repository activity, review coverage and bug density.')}
<p>Open and merged pull requests, findings, review count, approval rate and the most common bug category per repository. Useful for spotting the repository that quietly generates most of your risk.</p>

<h2 id="commits">Commit quality</h2>
{fig('reports-commits.jpg', 'Low-effort commit message detection.')}
<p>Flags commits whose messages carry no information - <em>wip</em>, <em>fix</em>, <em>update</em>, <em>asdf</em> - and shows the share per developer with examples. One definition of "low effort" is used everywhere, so this report and the daily effort report can never disagree about the same commit.</p>

<h2 id="daily">Daily activity</h2>
{fig('reports-daily.jpg', 'System-wide activity day by day.')}
<p>Pull requests opened and merged, commits, lines changed, findings and reviews per day. Good for spotting a release crunch or a quiet stretch.</p>

<h2 id="effort">Daily effort</h2>
{fig('reports-daily-effort.jpg', 'What each developer worked on, day by day.')}
<p>One row per developer per day: commits, lines, an active window and whether the day was productive. The active window is the span between first and last commit - a proxy for engaged time, not a timesheet. A single-commit day shows no span at all.</p>
<p>Sourced from all recorded commits, including direct pushes when <em>Record all push activity</em> is enabled, de-duplicated by SHA.</p>

<h2 id="ai-usage">AI usage</h2>
{fig('reports-ai-usage.jpg', 'Tokens and estimated cost for every provider call.')}
<table>
<tr><th>Panel</th><th>Meaning</th></tr>
<tr><td><strong>Estimated cost</strong></td><td>Derived from a maintained price table, not a bill. A model with no published rate on file records tokens with no cost rather than a guess.</td></tr>
<tr><td><strong>Tokens / Calls</strong></td><td>Totals and per-call averages.</td></tr>
<tr><td><strong>Cache hit rate</strong></td><td>Share of input served from cache, where the provider supports it.</td></tr>
<tr><td><strong>By operation</strong></td><td>Reviews, disputes, comment replies, assistant chats and connection tests. Automatic operations are tagged, so you can see which spend grows on its own.</td></tr>
<tr><td><strong>By model / repository</strong></td><td>Ordered by cost - a thousand cheap assistant messages matter less than fifty large reviews.</td></tr>
<tr><td><strong>Largest single calls</strong></td><td>The outliers, which is where tuning pays off.</td></tr>
</table>
<div class="tip"><strong>Bringing the cost down</strong>
<p>The diff dominates the token cost. <code>REVIEW_MAX_TOTAL_PATCH_BYTES</code> (default 120&nbsp;KB, roughly 30k tokens) caps it. Past a few thousand lines the reviewer's findings stop improving while the bill keeps rising, so lowering it on a repository with huge generated diffs is usually free quality-wise. Choosing a cheaper model per repository is the other lever.</p></div>
""")

# ── Assistant ───────────────────────────────────────────────────────────────
write('assistant', 'Using PullLens', 'AI assistant',
  'Ask questions about your own engineering data in plain language.',
  f"""
{fig('assistant.jpg', 'The assistant, with starter questions and a provider selector.')}
<h2 id="what">What it can do</h2>
<p>The assistant queries the data PullLens has already collected and answers in plain language - useful when you know the question but not which report holds the answer.</p>
<ul>
  <li>“Who is the most productive developer this month?”</li>
  <li>“What is the team overview for the last 30 days?”</li>
  <li>“Which repository has the lowest health score?”</li>
  <li>“How many low-effort commits were made this week?”</li>
</ul>

<h2 id="scope">What it will not do</h2>
<p>It is deliberately scoped to PullLens data. It will not answer general programming questions, write code, or discuss anything outside your metrics - instructions inside a conversation cannot widen that scope.</p>

<h2 id="how">How it works</h2>
<table>
<tr><th>Aspect</th><th>Detail</th></tr>
<tr><td><strong>Provider</strong></td><td>Uses your default AI provider; pick another from the selector. Each message costs a call - see <a href="reports.html#ai-usage">AI Usage</a>.</td></tr>
<tr><td><strong>History</strong></td><td>Kept server-side per user for seven days, capped at 50 turns. The browser's copy is for display only and is never used as model input - otherwise a crafted request could put words in the assistant's mouth.</td></tr>
<tr><td><strong>Privacy</strong></td><td>Your conversation is yours; nobody else's account can read it. <em>Clear history</em> deletes it.</td></tr>
<tr><td><strong>Rate limit</strong></td><td>20 messages per minute per user, because every message spends provider credit.</td></tr>
</table>
""")

# ── Users, roles, access ────────────────────────────────────────────────────
write('users-roles', 'Administration', 'Users, roles and access',
  'Who can sign in, what each role may do, and how to limit someone to specific repositories.',
  f"""
<h2 id="accounts">Creating accounts</h2>
{fig('users.jpg', 'Users → Accounts. There is no public sign-up; accounts are created here.')}
<p>Go to <strong>Users → Accounts</strong> and press <strong>Add User</strong>. Set a name, email, password and role. New accounts are marked verified immediately - an administrator vouched for the address and there is no self-service flow to click a link from.</p>
<div class="note"><strong>You cannot edit or delete your own account here</strong>
<p>Use <a href="account.html">Profile Settings</a> instead. This prevents an administrator locking themselves out or changing their own permissions in place. The last remaining administrator also cannot delete their own account - with registration disabled, that would leave an installation nobody can administer.</p></div>

<h2 id="roles">The four roles</h2>
{fig('roles.jpg', 'Users → Roles & Permissions. Locked permissions cannot be unticked.')}
<table>
<tr><th>Role</th><th>Intended for</th><th>Can do</th></tr>
<tr><td><strong>Administrator</strong></td><td>Whoever runs the instance</td><td>Everything, including users, roles, credentials and observability.</td></tr>
<tr><td><strong>Manager</strong></td><td>Tech leads</td><td>Reports, findings (including resolving), repositories, triggering reviews, the assistant. Not users or credentials.</td></tr>
<tr><td><strong>Member</strong></td><td>The wider team</td><td>Read-only across all repositories: reports, tasks and findings.</td></tr>
<tr><td><strong>Contributor</strong></td><td>Contractors, external teams</td><td>Only the repositories granted to them. No aggregate reports.</td></tr>
</table>

<h2 id="permissions">Editing what a role may do</h2>
<p>The matrix on <strong>Users → Roles &amp; Permissions</strong> is editable. Changes apply immediately to everyone holding that role.</p>
<table>
<tr><th>Permission</th><th>Grants</th></tr>
<tr><td><code>users.manage</code></td><td>Create, edit and delete accounts</td></tr>
<tr><td><code>reports.view</code></td><td>Open the reports section</td></tr>
<tr><td><code>tasks.view</code></td><td>Open the tasks board</td></tr>
<tr><td><code>findings.view</code></td><td>See findings and browse repositories</td></tr>
<tr><td><code>findings.resolve</code></td><td>Close findings with a reason</td></tr>
<tr><td><code>repositories.view-all</code></td><td>See every repository rather than only granted ones</td></tr>
<tr><td><code>repositories.manage</code></td><td>Track and untrack repositories</td></tr>
<tr><td><code>integrations.manage</code></td><td>Configure the GitHub App and accounts</td></tr>
<tr><td><code>reviews.trigger</code></td><td>Queue reviews manually</td></tr>
<tr><td><code>ai-providers.manage</code></td><td>Configure AI providers and keys</td></tr>
<tr><td><code>assistant.use</code></td><td>Use the AI assistant</td></tr>
<tr><td><code>observability.view</code></td><td>Open Horizon and Telescope</td></tr>
</table>
<div class="warn"><strong>Two permissions are locked on Administrator</strong>
<p><code>users.manage</code> and <code>repositories.view-all</code> cannot be removed from the administrator role, and a change leaving no role able to manage users is refused. Registration is disabled, so a lockout would be unrecoverable.</p></div>

<h2 id="repo-access">Limiting someone to specific repositories</h2>
<p>Any role without <code>repositories.view-all</code> - Contributor by default - sees only what you grant. Editing such a user reveals a per-repository list:</p>
<table>
<tr><th>Level</th><th>Allows</th></tr>
<tr><td><strong>No access</strong></td><td>The repository is invisible to them.</td></tr>
<tr><td><strong>View only</strong></td><td>Read the repository, its pull requests, findings and tasks.</td></tr>
<tr><td><strong>View and manage</strong></td><td>Also resolve its findings and trigger reviews, if their role permits those actions.</td></tr>
</table>
<p>Scoping applies everywhere, not just the repository list: dashboard totals, findings, tasks and even the repository and developer dropdowns narrow to the granted set - so the filters cannot disclose that a repository exists. Editing the URL to a repository they were not granted returns 403.</p>
<div class="note"><strong>Why scoped users have no aggregate reports</strong>
<p>Team performance and commit quality span every repository and cannot be meaningfully narrowed to one person's subset, so those pages require <code>repositories.view-all</code>. Scoped users get the dashboard, repositories, findings and tasks, all correctly narrowed.</p></div>

<h2 id="observability">Horizon and Telescope</h2>
<p>Horizon shows the queue; Telescope shows requests and exceptions. Both expose payloads and credentials in cleartext, so both are restricted to administrators. Telescope is disabled entirely unless <code>TELESCOPE_ENABLED=true</code>.</p>
""")

# ── Account ─────────────────────────────────────────────────────────────────
write('account', 'Administration', 'Your account',
  'Profile, password, two-factor authentication, passkeys and appearance.',
  f"""
{fig('profile-settings.jpg', 'User Settings → Profile.')}
<h2 id="profile">Profile</h2>
<p>Change your name and email. Changing your email marks it unverified until you confirm the new address.</p>

<h2 id="security">Security</h2>
<p><strong>User Settings → Security</strong> requires you to re-enter your password before it opens, so a borrowed unlocked laptop cannot be used to change your credentials.</p>
<table>
<tr><th>Feature</th><th>Notes</th></tr>
<tr><td><strong>Password</strong></td><td>In production, passwords must be at least 12 characters with mixed case, numbers and symbols, and are checked against known breached-password lists. Rate limited to six attempts per minute.</td></tr>
<tr><td><strong>Two-factor authentication</strong></td><td>Scan the QR code with any authenticator app, then confirm with a code. Store the recovery codes somewhere safe - they are the way back in if you lose the device.</td></tr>
<tr><td><strong>Passkeys</strong></td><td>Sign in with Touch ID, Windows Hello or a hardware key instead of a password. You can register several - one per device is sensible.</td></tr>
</table>

<h2 id="appearance">Appearance</h2>
<p>Light, dark, or follow the system setting. Stored per browser.</p>

<h2 id="delete">Deleting your account</h2>
<p>Permanent, and requires your password. If you are the last administrator it is refused - promote someone else first.</p>
""")

# ── Troubleshooting ─────────────────────────────────────────────────────────
write('troubleshooting', 'Administration', 'Troubleshooting',
  'The things that usually go wrong, and how to tell which one you are looking at.',
  f"""
<h2 id="no-reviews">A pull request opened but nothing happened</h2>
<p>Work down this list in order - the cause is nearly always one of the first three.</p>
<ol class="steps">
  <li><strong>Is the repository tracked?</strong> Granting the GitHub App access is not the same as tracking. Check <strong>Repositories</strong>.</li>
  <li><strong>Can GitHub reach you?</strong> Open your GitHub App's <em>Advanced</em> tab and look at recent deliveries. Timeouts or connection errors mean your <code>APP_URL</code> is not publicly reachable. A 403 means the signature failed - usually a mismatched webhook secret.</li>
  <li><strong>Is an AI provider enabled?</strong> The dashboard says so plainly if not. Use <em>Test connection</em>.</li>
  <li><strong>Are reviews enabled for that repository?</strong> Check its settings - including <em>Tracked branches</em>, which silently skips pull requests targeting other branches.</li>
  <li><strong>Is the queue running?</strong> Reviews are background jobs. Check Horizon; if it is not running, nothing is processed.</li>
  <li><strong>Did the job fail?</strong> Horizon's failed jobs list shows the exception - most often a provider rate limit or an invalid key.</li>
</ol>

<h2 id="provider-errors">Provider errors</h2>
<table>
<tr><th>Symptom</th><th>Cause</th></tr>
<tr><td>“The API key was rejected”</td><td>Wrong or revoked key, or it lacks access to the selected model.</td></tr>
<tr><td>“Rate limiting this key”</td><td>Provider throttling. Reviews are already limited to five per minute; lower the volume or raise your quota.</td></tr>
<tr><td>“No remaining quota”</td><td>Billing exhausted at the provider.</td></tr>
<tr><td>“Did not recognise the requested model”</td><td>The model name is wrong or retired. Pick from the dropdown rather than typing one.</td></tr>
<tr><td>“Base URL could not be reached”</td><td>A custom base URL or Ollama address is wrong or unreachable from the server.</td></tr>
</table>
<div class="note"><strong>Detail lives in the log, not the browser</strong>
<p>Provider errors are shown as a short summary because the raw message often echoes the request back, API key included. The full text goes to your application log.</p></div>

<h2 id="webhooks">Webhook checklist</h2>
<ul>
  <li><code>APP_URL</code> must be the public HTTPS URL, not <code>localhost</code>.</li>
  <li>The webhook URL on the GitHub App must be <code>&lt;APP_URL&gt;/webhooks/github</code>.</li>
  <li>The webhook secret must match the one stored in PullLens. Re-running setup regenerates both together.</li>
  <li>Deliveries can be replayed from GitHub once the cause is fixed.</li>
</ul>

<h2 id="duplicates">A review ran twice</h2>
<p>Almost always a queue misconfiguration: <code>REDIS_QUEUE_RETRY_AFTER</code> must stay above the longest job timeout (300 seconds). Below it, the queue assumes a slow review is lost and hands it to a second worker while the first is still running - paying twice and posting twice.</p>

<h2 id="empty-reports">Reports are empty</h2>
<table>
<tr><th>Report</th><th>Needs</th></tr>
<tr><td>Tasks</td><td>Pull requests reviewed <em>and merged</em> since the feature was deployed. Not backfilled.</td></tr>
<tr><td>AI usage</td><td>Calls made since usage recording was deployed. Not backfilled.</td></tr>
<tr><td>Daily effort</td><td>Commits. Enable <em>Record all push activity</em> to include work outside pull requests.</td></tr>
<tr><td>Commit quality</td><td>Commits with messages - a repository tracked only today will look thin.</td></tr>
</table>

<h2 id="upgrade">After upgrading</h2>
<pre><code>php artisan migrate</code></pre>
<p>Run this after every upgrade. If reviews stopped saving after an upgrade, a pending migration is the first thing to check.</p>

<h2 id="help">Still stuck</h2>
<p>Open an issue on the repository, or email <a href="mailto:hello@vlancy.com">hello@vlancy.com</a>. Include your PullLens version, the provider and model, and the relevant lines from the application log - with any keys removed.</p>
""")

print(f"built {len(ORDER)} pages")
