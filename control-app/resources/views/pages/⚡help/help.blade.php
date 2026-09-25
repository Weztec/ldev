<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold">Help</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            How to use and configure Linux Dev, and how to set up different kinds of projects. For anything not
            covered here, the project's own <span class="font-mono">README.md</span> has the full installer details.
        </p>
    </div>

    @php

        $img = fn (string $name, string $alt) => '<img src="' . e(asset("img/help/{$name}.png"))
            . '" alt="' . e($alt) . '" loading="lazy" class="rounded border border-gray-200 dark:border-gray-700 shadow-sm max-w-full my-2" />';
@endphp

    <div class="flex flex-col md:flex-row gap-6">

        <nav class="md:w-56 shrink-0 space-y-0.5 md:sticky md:top-6 md:self-start md:max-h-screen md:overflow-y-auto">
            @foreach(self::SECTIONS as $key => $label)
                <button type="button" wire:click="setSection('{{ $key }}')"
                    class="w-full text-left px-3 py-1.5 rounded text-sm {{ $section === $key
                        ? 'bg-gray-100 dark:bg-gray-700 font-medium'
                        : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' }}">
                    {{ $label }}
                </button>
            @endforeach
        </nav>

        <div class="flex-1 min-w-0 bg-white dark:bg-gray-800 rounded-lg shadow p-5 space-y-4 text-sm leading-relaxed">

            @if($section === 'start')
                <h2 class="text-lg font-medium">Getting started</h2>
                <p>
                    Linux Dev is a Laravel Valet/Herd-style local development environment for Fedora 44 KDE Plasma:
                    nginx, PHP 7.4&ndash;8.5, MariaDB, PostgreSQL, Valkey, Memcached, Mailpit, RustFS (S3 storage), Supervisor,
                    mkcert and dnsmasq, provisioned by shell scripts, plus this dashboard for managing it day to day.
                </p>
                <h3 class="font-medium">Installing</h3>
                <p>From the Linux Dev checkout (this repository), as root with a real <span class="font-mono">sudo</span>, not <span class="font-mono">su -</span>:</p>
                <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 overflow-x-auto font-mono">sudo ./install.sh</pre>
                <p class="text-xs text-gray-500 dark:text-gray-400">If that says <span class="font-mono">Permission denied</span>, the scripts lost their executable flag when the download was extracted (some graphical archive tools do this). Restore it from the same folder with <span class="font-mono">chmod +x *.sh</span>, then run the install again.</p>
                <p>
                    That runs two scripts in order &mdash; <span class="font-mono">setup-environment.sh</span>
                    (installs and configures every OS-level service: nginx, PHP-FPM, MariaDB, PostgreSQL, and
                    the rest of the list above) and then <span class="font-mono">deploy-app.sh</span> (builds and
                    starts this dashboard itself). You don't need to run either one separately for a first
                    install &mdash; <span class="font-mono">install.sh</span> above does both.
                </p>
                <h3 class="font-medium">Re-running things later</h3>
                <p>Each is safe to run again on its own, from the same checkout:</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Changed <span class="font-mono">control-app/</span> (the dashboard's own code) and want to redeploy it:</p>
                <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 overflow-x-auto font-mono">sudo ./deploy-app.sh</pre>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Want to pick up newer versions of the underlying OS packages, or re-apply configuration:</p>
                <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 overflow-x-auto font-mono">sudo ./setup-environment.sh</pre>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Remove everything Linux Dev installed (your projects in <span class="font-mono">~/Sites</span> and your S3 bucket data are left alone):</p>
                <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 overflow-x-auto font-mono">sudo ./uninstall.sh</pre>
                <h3 class="font-medium">Opening the dashboard</h3>
                <p>
                    The dashboard listens on <span class="font-mono">http://127.0.0.1:8090</span> and is
                    token-gated &mdash; installing it adds a Linux Dev launcher to your applications menu, which opens
                    it with the token attached the first time; your browser remembers it after that (as a cookie).
                    If you ever get a 403, see <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('troubleshooting')">Troubleshooting</button>.
                </p>
                <h3 class="font-medium">Where things live</h3>
                <table class="w-full text-xs">
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr><td class="py-1 pr-3 font-mono whitespace-nowrap">~/Sites</td><td class="py-1 text-gray-500 dark:text-gray-400">Every project you create or link, one directory per project.</td></tr>
                        <tr><td class="py-1 pr-3 font-mono whitespace-nowrap">~/.ldev/app</td><td class="py-1 text-gray-500 dark:text-gray-400">This dashboard itself &mdash; fully rebuildable by <span class="font-mono">deploy-app.sh</span>.</td></tr>
                        <tr><td class="py-1 pr-3 font-mono whitespace-nowrap">~/.ldev/storage</td><td class="py-1 text-gray-500 dark:text-gray-400">Your S3 bucket data (RustFS) and Meilisearch's indexes. Never wiped by re-installing or uninstalling.</td></tr>
                        <tr><td class="py-1 pr-3 font-mono whitespace-nowrap">~/.config/ldev</td><td class="py-1 text-gray-500 dark:text-gray-400">nginx vhosts, certs, logs, Supervisor jobs, the dashboard token, backups.</td></tr>
                    </tbody>
                </table>
                <h3 class="font-medium">The sidebar</h3>
                <p>
                    The chevron next to the logo collapses the sidebar to icons only &mdash; useful on a small
                    screen or just to get more room; hover an icon to see its label. Help always stays pinned at
                    the bottom, separate from the rest of the menu.
                </p>
                <div class="flex flex-wrap items-start gap-4">
                    <div>{!! $img('sidebar-expanded', 'The sidebar expanded, showing every section with its icon and label') !!}<p class="text-xs text-gray-400 dark:text-gray-500 text-center mt-1">Expanded</p></div>
                    <div>{!! $img('sidebar-collapsed', 'The sidebar collapsed to icons only') !!}<p class="text-xs text-gray-400 dark:text-gray-500 text-center mt-1">Collapsed</p></div>
                </div>

            @elseif($section === 'sites')
                <h2 class="text-lg font-medium">The Sites list</h2>
                <p>The Sites page (the dashboard's home page) lists every project Linux Dev knows about, with five columns:</p>
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Name</strong> &mdash; links to that project's Overview page. Small badges under the name flag what needs attention, from the stored checks: <em>vulnerable</em> packages, <em>tests failing</em> (or <em>tests pass</em>) from the last test run, <em>update ready</em> or <em>update has problems</em> from the last dependency sandbox test, and the number of outdated packages.</li>
                    <li><strong>URL</strong> &mdash; its <span class="font-mono">https://name.test</span> address, opens in a new tab.</li>
                    <li><strong>Git</strong> &mdash; branch name and a dot showing whether it's clean, ahead/behind, or has no repository yet.</li>
                    <li><strong>Status</strong> &mdash; a real HTTPS request to the site right now, not just "is a config file present." Green means it actually answered.</li>
                    <li><strong>Actions</strong> &mdash; Clone, Delete from list, Delete from disk.</li>
                </ul>
                {!! $img('sites-list', 'The Sites list, with health badges under the project name and the URL, Git, Status and Actions columns') !!}
                <h3 class="font-medium">Adding a project you didn't create through the wizard</h3>
                <p>
                    If you copy a project into <span class="font-mono">~/Sites</span> by hand, or
                    <span class="font-mono">git clone</span> it there directly, click
                    <strong>Scan for untracked projects</strong>. It finds anything in <span class="font-mono">~/Sites</span>
                    that isn't registered yet, and lets you review &mdash; and edit &mdash; each one's PHP/Node
                    version and flags before adding it, rather than silently guessing. This also happens on its
                    own every 5 minutes in the background, so a copied-in project turns up without you doing anything.
                </p>
                <h3 class="font-medium">Removing a project</h3>
                <p>
                    <strong>Delete from list</strong> only removes it from the dashboard (and its nginx/Supervisor
                    config) &mdash; the project's files stay on disk, and "Scan for untracked projects" will find it
                    again later with its settings restored. <strong>Delete from disk</strong> does the same and then
                    permanently deletes the project directory, its certificate and its backups. Both show you the
                    project's git status first, with a one-click "Push now" if it has unpushed commits.
                </p>

            @elseif($section === 'new-project')
                <h2 class="text-lg font-medium">Creating a project</h2>
                <p>Click <strong>New Project</strong> on the Sites page. The wizard has two steps.</p>
                <h3 class="font-medium">Step 1</h3>
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Source</strong>: <em>Scaffold a new Laravel project</em>, or <em>Clone an existing repository</em>.</li>
                    <li><strong>Name</strong>: lowercase letters, digits and hyphens only &mdash; this becomes both the directory under <span class="font-mono">~/Sites</span> and the <span class="font-mono">name.test</span> domain.</li>
                    <li><strong>PHP version</strong> and <strong>Node.js version</strong> &mdash; default to whatever you've set on the Settings page (normally the newest available).</li>
                    <li>
                        <strong>Database</strong>: SQLite, MySQL, PostgreSQL, or None (for a plain static site or a
                        project that manages its own database). Choosing MySQL/PostgreSQL then offers
                        <em>Create a new database</em> (name it, or leave blank to use the project name) or
                        <em>Use an existing database</em> from a live list of what's already on the server &mdash;
                        handy when cloning a project whose database already exists.
                    </li>
                </ul>
                {!! $img('wizard-step1-database', 'Step 1 of the New Project wizard, with MySQL selected and the create-new/use-existing database choice showing') !!}
                <p>
                    Cloning instead needs a <strong>repository URL</strong>. An SSH URL
                    (<span class="font-mono">git@host:user/repo.git</span>) uses whatever SSH key is already
                    trusted on this machine and needs no token. An <span class="font-mono">https://</span> URL
                    needs a saved token from the Repositories page if the repository is private.
                </p>
                {!! $img('wizard-step1-clone', 'Step 1 with "Clone an existing repository" selected, showing the connection type and repository URL fields') !!}
                <h3 class="font-medium">Step 2</h3>
                <p>For a fresh scaffold: an optional starter kit (Breeze or Jetstream, with a stack and a few
                    options each), whether to use Pest instead of PHPUnit for tests (ldev installs Pest and converts
                    the example tests), whether to also provision S3-compatible storage or Laravel Reverb, and
                    whether to create a brand-new remote repository (GitHub or Bitbucket) for it and push the
                    initial commit.</p>
                {!! $img('wizard-step2-scaffold', 'Step 2 for a fresh scaffold, showing the starter kit dropdown and the Pest, repository, S3 and Reverb checkboxes') !!}
                <p>Click <strong>Create project</strong>. Scaffolding a new project genuinely takes 30&ndash;90
                    seconds (composer/npm installs, migrations) &mdash; the button shows a loading state the whole
                    time. You're redirected to the dashboard once it's done.</p>

            @elseif($section === 'project-types')
                <h2 class="text-lg font-medium">Setting up different kinds of projects</h2>
                <h3 class="font-medium">A plain Laravel app</h3>
                <p>The default path: Scaffold, SQLite, no starter kit. Ready immediately at
                    <span class="font-mono">https://name.test</span>.</p>
                <h3 class="font-medium">An app that needs MySQL or PostgreSQL</h3>
                <p>Pick the driver in step 1 and either create a new database or point it at one that already
                    exists. You can change this later at any time from that project's <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('project-settings')">Project settings</button> &rarr; Environment panel.</p>
                <h3 class="font-medium">A static site, or a non-Laravel project (e.g. WordPress)</h3>
                <p>Use <em>Clone an existing repository</em>. For a plain static site or anything that manages
                    its own database connection, choose Database: <strong>None</strong> &mdash; Linux Dev won't
                    provision anything on top of it. For WordPress specifically, choose <strong>MySQL</strong>:
                    Linux Dev detects <span class="font-mono">wp-config-sample.php</span> and writes a real
                    <span class="font-mono">wp-config.php</span> with the database connection filled in
                    automatically (WordPress doesn't use a <span class="font-mono">.env</span> file).</p>
                <h3 class="font-medium">A realtime app (Laravel Reverb / broadcasting)</h3>
                <p>Turn on <strong>Reverb</strong> when creating the project, or later from Project settings
                    &rarr; Flags. Reverb gets its own subdomain, <span class="font-mono">name-reverb.test</span>,
                    served over TLS so a browser on the HTTPS page doesn't block the WebSocket connection as mixed
                    content.</p>
                <h3 class="font-medium">An app that routes by subdomain</h3>
                <p>Every site's certificate already covers <span class="font-mono">*.name.test</span>, and nginx
                    routes any subdomain of a project to that same project. Nothing extra to configure here &mdash;
                    the project's own routing (e.g. <span class="font-mono">Route::domain('{subdomain}.name.test')</span>)
                    decides what to do with it.</p>

            @elseif($section === 'overview-page')
                <h2 class="text-lg font-medium">A project's Overview page</h2>
                <p>Click a project's name to open it. This is the "check it and move on" page &mdash; it refreshes
                    itself automatically (the dot in the top right shows it's live; use <strong>Pause page
                    updates</strong> to freeze it while you're reading something). <strong>Dependencies</strong> and <strong>Tests</strong> start collapsed, showing only a one-line summary (advisories, outdated counts, sandbox state; passed/failed from the last run). Click the title to expand; their buttons (such as <em>Run all</em>) expand them too. A tab bar under the title
                    switches to <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('project-settings')">Project settings</button>.</p>
                {!! $img('overview-tabs', 'The Overview / Project settings tab bar') !!}
                <p>A project without an <span class="font-mono">ldev.json</span> shows a notice at the top of this page. <strong>Create ldev.json</strong> writes one from the project's current settings, ready to commit (see Project settings &rarr; Project file).</p>
                {!! $img('overview-ldevjson-notice', 'The notice shown when a project has no ldev.json yet, with its Create ldev.json button') !!}
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Quick launch</strong> &mdash; one click to Mailpit (catches all outbound mail), the S3 storage console, Meilisearch (when the project uses it), Adminer, and the Logs page.</li>
                    <li><strong>Details</strong> &mdash; the project's real path and detected framework/version.</li>
                    <li><strong>Git</strong> &mdash; branch, dirty/ahead/behind status, a diff viewer, and a Push button.</li>
                    <li><strong>Resources</strong> &mdash; disk usage and how many PHP-FPM workers are actually running for this project's PHP version.</li>
                    <li><strong>Public demo link</strong> &mdash; a free, no-signup Cloudflare quick tunnel, for showing a project to someone outside your machine. Start it right before a call, stop it after &mdash; it's meant to be temporary, not a permanent public URL.</li>
                </ul>
                {!! $img('overview-quicklaunch', 'The Quick launch panel with links to Mailpit, the S3 storage console, Adminer and Logs') !!}
                {!! $img('overview-grid', 'The Details, Git, Resources and Public demo link panels side by side') !!}
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Dependencies</strong> &mdash; security advisories and outdated Composer/npm packages, with updates tested in a sandbox copy before they touch the project. See <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('dependencies-tests')">Dependencies &amp; tests</button>.</li>
                    <li><strong>Tests</strong> &mdash; run all of the project's Pest/PHPUnit tests or pick files and single tests, with a result for every test and your own per-machine test settings. See <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('dependencies-tests')">Dependencies &amp; tests</button>.</li>
                    <li><strong>Dumps</strong> &mdash; <strong>Send dump() and dd() here</strong> collects the project's <span class="font-mono">dump()</span> and <span class="font-mono">dd()</span> output on this page instead of in the browser, with the time, file and line, and the request or artisan command it came from. That makes JSON responses, Livewire requests, queued jobs and commands easy to debug. It adds <span class="font-mono">VAR_DUMPER_FORMAT</span> and <span class="font-mono">VAR_DUMPER_SERVER</span> to the project's <span class="font-mono">.env</span>, and switching off removes them. The collector (<span class="font-mono">ldev-dumps</span>) only listens on this machine; if it isn't running, dumps show in the page as usual. The last 100 are kept.</li>
                    <li><strong>Background processes</strong> &mdash; live status and recent output for the queue worker, Reverb, the scheduler, and any custom jobs (see <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('background-jobs')">Background jobs</button>).</li>
                    <li><strong>Application log</strong> &mdash; a tail of this project's own <span class="font-mono">storage/logs/laravel.log</span>.</li>
                </ul>
                {!! $img('overview-dumps', 'The Dumps panel showing a dump from a web request and one from an artisan tinker command') !!}
                {!! $img('overview-background', 'The Background processes panel showing each program\'s live status') !!}
                {!! $img('overview-applog', 'The Application log panel') !!}

            @elseif($section === 'dependencies-tests')
                <h2 class="text-lg font-medium">Dependencies &amp; tests</h2>
                <p>Two sections on a project's <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('overview-page')">Overview page</button>.
                    Both start collapsed with a one-line summary; click the title to open them. They stay open while the page refreshes.</p>
                {!! $img('deps-collapsed', 'The collapsed Dependencies section showing a one-line summary') !!}

                <h3 class="font-medium">Dependencies</h3>
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>What it checks</strong> &mdash; known security advisories for Composer packages (<span class="font-mono">composer audit</span>) and npm packages (<span class="font-mono">npm audit</span>), and outdated Composer and npm packages. Major-version jumps are shown in yellow. It runs once a day for every project, or when you click <em>Check now</em>.</li>
                    <li><strong>The lists</strong> &mdash; <em>Security advisories, Composer</em>, <em>Security advisories, npm</em>, <em>Outdated Composer packages</em> and <em>Outdated npm packages</em> are collapsed; click one to open it. Each vulnerable package shows a one-line count by severity (for example <em>31 advisories: 14 high, 16 moderate, 1 low</em>); click it to read every advisory with its link. Tick packages and click <em>Test selected</em>, or use <em>Test all</em> for the whole list. For npm advisories, <em>Test npm audit fix</em> tries npm's own fix for everything it can.</li>
                    <li><strong>Sandbox test</strong> &mdash; nothing is updated straight away. ldev copies the project to <span class="font-mono">~/.ldev/storage/dependency-sandbox</span> (a near-instant copy on btrfs), runs the update there in the background, then checks that the app still boots and its routes load, Blade views compile, the front end builds (<span class="font-mono">npm run build</span>) and the tests pass (with your <em>Test environment</em> settings). It also re-runs <span class="font-mono">composer audit</span> to show which advisories the update fixes and which are still open.</li>
                    <li><strong>Notes</strong> &mdash; each problem is listed in plain words. If something fails, the same check is run on an untouched copy of the project, so the note tells you whether the update caused it or it was already broken. <em>Version changes</em> lists every package that would move, including indirect ones.</li>
                    <li><strong>Apply to project</strong> &mdash; copies the tested <span class="font-mono">composer.json/composer.lock</span> (or <span class="font-mono">package.json/package-lock.json</span>) into the project and runs <span class="font-mono">composer install</span> or <span class="font-mono">npm install</span>, so you get exactly the versions that were tested. If problems were found the button says <em>Apply anyway</em>. It refuses if those files changed in the project after the test. <em>Discard sandbox</em> deletes the copy without changing anything.</li>
                    <li><strong>Major upgrades</strong> &mdash; <em>Test selected</em> and <em>Test all</em> stay within the version constraints in <span class="font-mono">composer.json</span>/<span class="font-mono">package.json</span>. For a yellow major-version jump (for example Laravel 12 to 13), tick it and click <em>Test major upgrade</em>, or <em>Test all majors</em>. The sandbox raises the constraint in its copy only (<span class="font-mono">composer require</span> or <span class="font-mono">npm install</span>, keeping dev packages as dev), then runs every check, so you see what breaks before touching the project. Applying it copies the raised constraint into the project too.</li>
                    <li><strong>Notification</strong> &mdash; when a sandbox test finishes you get a desktop notification saying whether it found problems, so you don't have to watch the page.</li>
                </ul>
                {!! $img('deps-sandbox', 'The Dependencies section with npm security advisories, outdated packages with the major-upgrade buttons, and a finished major-upgrade sandbox test') !!}

                <h3 class="font-medium">Tests</h3>
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Finding tests</strong> &mdash; ldev reads the test suites in <span class="font-mono">phpunit.xml</span> (or <span class="font-mono">tests/</span>) and lists every Pest and PHPUnit test by file. If the project has Vitest or Jest installed, its JavaScript test files (<span class="font-mono">*.test.js</span>, <span class="font-mono">*.spec.ts</span> and so on, outside <span class="font-mono">node_modules</span>) are listed too, in a <em>JavaScript</em> suite. <em>Reload list</em> picks up new tests.</li>
                    <li><strong>Running</strong> &mdash; <em>Run all</em> runs everything. To run only some, tick a file (all of its tests) or single tests and click <em>Run selected</em>. <em>Run failed</em> runs only the tests that failed last time. <em>Run a suite</em> runs one of the suites from phpunit.xml (such as <em>Unit</em> or <em>Feature</em>) or the JavaScript tests. Runs happen in the background with the project's own PHP and Node versions, one file at a time, so a file that crashes (for example a PHP fatal error) shows its error without hiding the other files' results. You get a desktop notification with the totals when a run finishes.</li>
                    <li><strong>Choosing the runner</strong> &mdash; ldev picks the right runner for each file automatically, and labels each file <em>Pest</em>, <em>PHPUnit</em>, <em>Vitest</em> or <em>Jest</em>, plus its suite. When the project has Pest installed, PHP tests run through Pest (it runs PHPUnit test classes too). When it only has PHPUnit, PHPUnit test classes run as normal and Pest-style files are marked <em>needs Pest</em> with the command to install it, instead of crashing. JavaScript files run through the project's own Vitest or Jest.</li>
                    <li><strong>Results</strong> &mdash; each test shows <em>passed</em>, <em>failed</em>, <em>error</em>, <em>skipped</em> or <em>not run</em>, with its time, and failures show their message and trace. Each file shows its passed, failed and skipped counts. Use <em>Show</em> to list all tests, failures only or skipped only. <em>Clear results</em> deletes the stored results.</li>
                    <li><strong>Skipped</strong> &mdash; the test chose not to run, usually through <span class="font-mono">markTestSkipped()</span> or <span class="font-mono">->skip()</span> in the project's own code. It is not a failure, and changing settings will not make it run. The reason the test gives is shown under it.</li>
                    <li><strong>Hints</strong> &mdash; common setup problems are called out above the results, such as the database refusing the test credentials, a missing test database, or files that crashed.</li>
                    <li><strong>Safety</strong> &mdash; if the tests would run against your development database (phpunit.xml does not point them at a separate one), a warning is shown and you are asked to confirm before running.</li>
                    <li><strong>Code coverage</strong> &mdash; tick <em>Measure code coverage</em> before running to see how much of your code the tests actually execute, using Xdebug (installed with every ldev PHP version). It covers the code listed in phpunit.xml's <span class="font-mono">&lt;source&gt;</span> section (usually <span class="font-mono">app/</span>) and shows the total plus the least-covered files. It is slower than a normal run and applies to PHP tests only.</li>
                    <li><strong>Recent runs</strong> &mdash; the last 10 runs are kept with their totals, time and coverage, so you can see whether things are getting better or worse. A test that failed in one run and passed in another is listed as <em>possibly flaky</em>: its result may depend on timing, test order or shared state rather than the code. <em>Clear history</em> removes the list.</li>
                </ul>
                {!! $img('tests-results', 'The Tests section with suite buttons, Run failed, and results per file including a failed test and JavaScript tests') !!}
                {!! $img('tests-coverage-history', 'Code coverage with the least-covered files, and the Recent runs table with a possibly flaky test') !!}

                <h3 class="font-medium">Test environment</h3>
                <p>Each developer's machine can need different test settings (database password, host, API keys). <em>Test environment</em>, inside the Tests section, holds your own values for this project. They are kept in ldev on this machine, never written into the project, so they do not show up in git or affect anyone else.</p>
                <ul class="list-disc pl-5 space-y-1">
                    <li>Every <span class="font-mono">&lt;env&gt;</span> value from the project's <span class="font-mono">phpunit.xml</span> is listed with its project value. Tick <em>Override</em> and type your own value, or use <em>Add variable</em> for anything else. Changes save automatically.</li>
                    <li>Overrides are passed to every test run from the dashboard, including the dependency sandbox. They take priority over both <span class="font-mono">phpunit.xml</span> and <span class="font-mono">.env</span>. The only exception is an entry marked <span class="font-mono">force="true"</span> in phpunit.xml, which PHPUnit always applies; those are marked <em>(forced)</em>.</li>
                    <li><em>Use ldev database settings</em> sets host <span class="font-mono">127.0.0.1</span>, user <span class="font-mono">root</span> (MariaDB) or <span class="font-mono">postgres</span>, and an empty password, which is how ldev's databases are set up. <em>Create database</em> appears when the test database does not exist yet.</li>
                    <li>The trash button removes an added variable, or switches an overridden one back to the project value.</li>
                    <li>To change the project's own shared copy for everyone, edit <span class="font-mono">phpunit.xml</span> or <span class="font-mono">.env.testing</span> under <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('project-settings')">Project settings</button> &rarr; Environment / project files.</li>
                </ul>
                {!! $img('tests-environment', 'The Test environment editor with one overridden value and one added variable') !!}

            @elseif($section === 'project-settings')
                <h2 class="text-lg font-medium">Project settings</h2>
                <p>The second tab on a project's page &mdash; everything that configures how the project runs,
                    kept separate from the Overview page so a background refresh there can never overwrite
                    something you're mid-editing here.</p>
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Project file (<span class="font-mono">ldev.json</span>)</strong> &mdash; <strong>Save current settings to ldev.json</strong> writes this site's PHP and Node versions, database driver and name, flags, queue settings and custom jobs to a file in the project root. New projects created from a starter kit get one automatically, included in their first commit; for any other project without one, the Overview page shows a <strong>Create ldev.json</strong> notice. <strong>View file</strong> opens it in the Environment / project files editor, where it can also be edited (it is checked as JSON before saving). Commit it, and anyone who adds or clones the project gets the same setup automatically (a cloned repository's ldev.json replaces the wizard's PHP, Node and database choices). The file never holds passwords or <span class="font-mono">.env</span> values. Changing the PHP or Node version never changes the file on its own, so you can try a version safely: a note under the dropdown says you're trying it on this machine only, with <strong>Keep … for everyone</strong> (writes just that version to ldev.json, ready to commit) and <strong>Switch back to …</strong>. When the site and the file differ, the card lists the differences and <strong>Apply project file</strong> brings the site back in line; the database is only set up from the file when a project is first added. An optional <span class="font-mono">requires</span> section, such as <span class="font-mono">{"mariadb": "&gt;=11.4"}</span>, states the service versions the project needs (mariadb, postgresql, valkey, memcached, nginx). Linux Dev checks them against what's installed and shows a <em>requirements not met</em> badge on the Sites list when they don't match. Services are shared by every project, so they are checked, not run per project the way PHP and Node versions are.</li>
                </ul>
                {!! $img('settings-projectfile', 'The Project file card listing a difference between the site and its ldev.json, and one service requirement met and one not') !!}
                {!! $img('settings-ldevjson-view', 'ldev.json opened with View file, in the Environment / project files editor') !!}
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Environment</strong> &mdash; change the PHP version, Node.js version, or database driver at any time, and restart PHP-FPM. Changing the database offers the same create-new/use-existing choice as the wizard, plus the option to run migrations and/or seed the new one.</li>
                </ul>
                {!! $img('settings-environment', 'The Environment panel with PHP version, Node.js version and Database dropdowns') !!}
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Flags</strong> &mdash; Xdebug, Queue worker, Reverb, Scheduler, Meilisearch, and automatic daily database backup, each a single switch. <strong>Meilisearch search</strong> connects the project to the local Meilisearch, a fast, typo-tolerant search engine with filters and facets: it sets <span class="font-mono">MEILISEARCH_HOST=http://127.0.0.1:7700</span> and an empty <span class="font-mono">MEILISEARCH_KEY</span>, which any Meilisearch client can read, plus <span class="font-mono">SCOUT_DRIVER=meilisearch</span> for projects that use Laravel Scout (install <span class="font-mono">laravel/scout</span> and <span class="font-mono">meilisearch/meilisearch-php</span>). Projects that already require <span class="font-mono">meilisearch/meilisearch-php</span> are switched on automatically when added.</li>
                </ul>
                {!! $img('settings-flags', 'The Flags panel with its six switches, including Meilisearch') !!}
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Queue worker settings</strong> &mdash; which queues to listen on (comma-separated, e.g. <span class="font-mono">xero,default</span> &mdash; a worker only processes "default" unless told otherwise), how many worker processes, sleep/tries/max-time. Only shown once the Queue worker flag above is on.</li>
                    <li><strong>Custom background jobs</strong> &mdash; see <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('background-jobs')">Background jobs</button> below.</li>
                </ul>
                {!! $img('settings-customjobs-summary', 'The Custom background jobs summary card with its Manage button') !!}
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Local hosts</strong> &mdash; extra hostnames (e.g. a dynamically-created subdomain) resolved without waiting on DNS.</li>
                </ul>
                {!! $img('settings-localhosts', 'The Local hosts panel') !!}
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Database backups</strong> &mdash; manual backup, restore, import, and download. See <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('backups')">Backups &amp; recovery</button>.</li>
                    <li><strong>Composer credentials</strong> &mdash; credentials for private Composer repositories that apply to this project only, such as its own Flux Pro license. They override the global ones from the Settings page for the same host; hosts you don't override keep using the global credentials. The list shows both, with <em>Override for this project</em> next to each global one. They are written to the project's own <span class="font-mono">auth.json</span>, which Composer reads wherever it runs (the dashboard or your terminal). ldev makes sure that file is git-ignored (adding it to <span class="font-mono">.git/info/exclude</span> on this machine if the project's <span class="font-mono">.gitignore</span> doesn't cover it) and refuses to save if <span class="font-mono">auth.json</span> is already tracked by git, so a license key is never committed. Deleting an override puts the project back on the global credentials.</li>
                    <li><strong>Environment / project files</strong> &mdash; a direct editor for <span class="font-mono">.env</span>, its rolling backup, and <span class="font-mono">.gitignore</span>, plus <span class="font-mono">.env.example</span>, <span class="font-mono">.env.testing</span>, <span class="font-mono">phpunit.xml</span> and <span class="font-mono">phpunit.xml.dist</span> when the project has them. The phpunit files are checked for valid XML before saving. These are the project's shared copies; for test settings that only apply to your machine, use <em>Test environment</em> on the Overview.</li>
                </ul>
                {!! $img('settings-envfile', 'The Environment / project files editor with its .env, .env.example, phpunit.xml, ldev.json and .gitignore tabs (placeholder content, not a real key)') !!}

            @elseif($section === 'background-jobs')
                <h2 class="text-lg font-medium">Background jobs (Supervisor)</h2>
                <p>Every project can run background processes managed by Supervisor, shown on the Overview page
                    and configured from Project settings.</p>
                <h3 class="font-medium">Built in</h3>
                <p>Queue worker, Laravel Reverb, and the scheduler (Laravel's <span class="font-mono">schedule:work</span>,
                    a stand-in for a real crontab entry) &mdash; each just a switch under Flags.</p>
                <h3 class="font-medium">Custom jobs</h3>
                <p>For anything else &mdash; Horizon, Pulse, a one-off report, a command that only needs to run
                    daily/weekly/monthly. Open <strong>Custom background jobs</strong> &rarr; <strong>Manage</strong>
                    on Project settings:</p>
                <ul class="list-disc pl-5 space-y-1">
                    <li>Pick a built-in preset from the <strong>Job</strong> dropdown, or start from Custom command.</li>
                    <li>Choose how it runs: <strong>Continuously</strong> (stays running, like a queue worker), or on a
                        <strong>schedule</strong> &mdash; daily, weekdays, weekly, twice a week, monthly, twice a
                        month, hourly, every 5 minutes, or any cron expression you type in directly. The form shows
                        the next three real run times before you save, so you can check it's right.</li>
                    <li>Once added, each job has a switch, Restart, Edit, Delete, and <strong>Run now</strong> (runs
                        it once immediately, in the foreground, useful for testing without waiting for its schedule).</li>
                </ul>
                {!! $img('customjobs-modal-schedule', 'The Manage jobs modal with "Artisan command on a schedule" selected, showing the cron field and next-run preview') !!}
                <h3 class="font-medium">Templates</h3>
                <p>Save any job as a <strong>template</strong> (a name plus the current form) and it becomes
                    selectable from the Job dropdown on <em>every</em> project, not just this one &mdash; define a
                    job once, reuse it anywhere.</p>
                <h3 class="font-medium">Why saving takes a moment</h3>
                <p>Every change is checked against Supervisor's own real parser before it's written anywhere,
                    so a typo in a command can't take down another project's background jobs. This also means
                    saving briefly restarts every project's background processes, not just this one's.</p>

            @elseif($section === 'databases')
                <h2 class="text-lg font-medium">Databases &amp; Adminer</h2>
                <p>The Databases page shows every database on this machine's MariaDB and PostgreSQL servers, live
                    (not from any dashboard record). Click a database's name to open it directly in Adminer, or
                    use the top button for the general login screen.</p>
                {!! $img('databases', 'The Databases page with the MariaDB and PostgreSQL panels') !!}
                <p>Both engines are set up for passwordless local access &mdash; MariaDB as
                    <span class="font-mono">root@127.0.0.1</span>, PostgreSQL as <span class="font-mono">postgres@127.0.0.1</span>
                    &mdash; deliberately, the same way Valet/Herd-style tools do: this is a single-user local dev
                    box reachable only from your own machine, not a shared server. Adminer logs you straight in
                    with no password to type, for the same reason.</p>
                <p>When creating or reconfiguring a project's database, you can create a brand-new one or point at
                    one already listed here &mdash; see <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('new-project')">Creating a project</button> and <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('project-settings')">Project settings</button>.</p>

            @elseif($section === 'services')
                <h2 class="text-lg font-medium">Services &amp; PHP versions</h2>
                <h3 class="font-medium">Services page</h3>
                <p>A switch per background service this machine runs: nginx, PHP-FPM, MariaDB, PostgreSQL,
                    Valkey (a Redis drop-in), Memcached and Supervisor (system-wide services shared by every
                    project), plus Mailpit, RustFS (S3 storage), Meilisearch and the dump collector (run as your own user). Use this to stop something you're
                    not using, or restart something that's misbehaving.</p>
                <p>Status refreshes every 15 seconds while the page is open. A service that crashed shows a red
                    dot and <em>failed</em>; one you switched off yourself just shows grey. Above the list are
                    cards for the machine itself: OS and kernel, uptime and load, memory and free disk space,
                    how much data MariaDB and PostgreSQL hold, and when the next HTTPS certificate expires.</p>
                <p><strong>Load average</strong> is how many processes were running or waiting for a CPU, averaged
                    over the last 1, 5 and 15 minutes (shown in that order). Read it against the CPU count next to it:
                    below that number the machine keeps up, at it every CPU is busy, and above it work is queuing.
                    The label beside it sums this up from the 1-minute figure: <em>Quiet</em>, <em>Busy</em> (70% of
                    the CPUs or more) or <em>Overloaded</em> (more than the CPUs can handle). The 15-minute figure
                    tells you whether a spike is brief or ongoing.</p>
                {!! $img('services-system', 'The System card with OS, kernel, uptime and the labelled 1, 5 and 15 minute load averages') !!}
                {!! $img('services', 'The Services page, with the System, Memory & disk and Databases & certificates cards above one switch per service and its installed version') !!}
                <h3 class="font-medium">PHP Versions page</h3>
                <p>Every PHP version Linux Dev installs (7.4 through 8.5) runs its own PHP-FPM pool, independently
                    startable/stoppable here. A project's chosen PHP version (set in the wizard or Project
                    settings) is just which pool its nginx vhost talks to &mdash; the pool for that version needs
                    to actually be running for the site to load.</p>
                {!! $img('php-versions', 'The PHP Versions page, one switch per PHP version') !!}

            @elseif($section === 'repositories')
                <h2 class="text-lg font-medium">Repositories</h2>
                <p>Save a GitHub or Bitbucket personal access token here to clone private repositories or create
                    new ones from the New Project wizard. Add a token with a provider, username, the token/secret
                    itself, and optionally an expiry date &mdash; the page warns you as it approaches, since
                    neither provider offers a way to auto-renew one.</p>
                {!! $img('repositories', 'The Repositories page: the saved-token table, then each account\'s repositories with the latest commit\'s date and author, a Public or Private badge and a Clone button') !!}
                <p>Once a token is saved, this page also lists every repository it can see, each with a
                    <strong>Clone</strong> button that jumps straight into the New Project wizard with the URL
                    already filled in. Each repository also shows when its latest commit was made and who made it,
                    and whether it is <strong>Public</strong> (green) or <strong>Private</strong> (grey).</p>
                <p>Prefer not to use a token at all? An <span class="font-mono">https://</span> URL needs one only
                    for a private repository &mdash; an SSH URL (<span class="font-mono">git@host:user/repo.git</span>)
                    uses whatever SSH key this machine already has trusted with that host instead.</p>

            @elseif($section === 'logs')
                <h2 class="text-lg font-medium">Logs</h2>
                <p>One place to read every log Linux Dev knows about: this dashboard's own log, nginx's
                    access/error log, each project's own Supervisor-managed process logs (queue/Reverb/scheduler/
                    custom jobs), and each project's Laravel application log. Pick one from the list, Refresh, or
                    Clear it (or Clear all). A project's own Overview page also has a shortcut straight to its
                    application log, for the most common "why did this just fail" check.</p>
                {!! $img('logs', 'The Logs page, a file list on the left and the selected log\'s content on the right') !!}

            @elseif($section === 'settings-page')
                <h2 class="text-lg font-medium">Dashboard settings</h2>
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Dashboard access</strong> &mdash; the token gating this whole app, and a way to regenerate it (this re-cookies your browser automatically, so you won't be locked out).</li>
                </ul>
                {!! $img('settings-dashboard-access', 'The Dashboard access panel, with the current token masked and a Regenerate button') !!}
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Defaults</strong> &mdash; the PHP and Node.js versions a new project starts with, unless you pick something else. Both default to the newest available.</li>
                </ul>
                {!! $img('settings-defaults', 'The Defaults panel') !!}
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Appearance</strong> &mdash; light, dark, or match your system.</li>
                </ul>
                {!! $img('settings-appearance', 'The Appearance panel, Light/Dark/System buttons') !!}
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Updates</strong> &mdash; shows the installed Linux Dev version (also shown under Help at the bottom of the sidebar) and checks GitHub for a newer release. When one is out, the sidebar says <em>Update available</em>, <strong>Download</strong> fetches the new release as a zip, and <strong>Update</strong> shows the exact command to run in a terminal, with a <strong>Copy</strong> button (for a zip install it downloads and unpacks the release instead of using <span class="font-mono">git pull</span>), for example <span class="font-mono">cd ~/ldev &amp;&amp; git pull &amp;&amp; sudo ./install.sh</span>. It needs your sudo password, so it can't run from the dashboard itself, shows the npm that comes with each installed Node version, with an <strong>Upgrade npm</strong> button when a newer, compatible npm is out (it runs <span class="font-mono">npm install -g npm@&lt;latest&gt;</span> for that Node version only, the same as the upgrade notice npm prints in a terminal), and checks PHP/Node/Laravel/Livewire/Flux against what's actually current upstream, once a day automatically or on demand. It also lists pending Fedora updates for the stack's own packages (nginx, PHP, MariaDB, PostgreSQL, Valkey and so on) with a ready-to-copy <span class="font-mono">sudo dnf upgrade</span> command. When anything is available, a yellow banner appears at the top of every page and the Settings link gets a badge. Dismissing the banner hides it until something new comes out.</li>
                </ul>
                {!! $img('settings-updates', 'The Updates panel showing the installed Linux Dev version, the npm for each installed Node version with Upgrade npm buttons, and each component checked against its latest release') !!}
                <ul class="list-disc pl-5 space-y-1">
                    <li><strong>Desktop notifications</strong> &mdash; a KDE notification when a service fails, a certificate is about to expire, disk space runs low (checked every 5 minutes), or new updates appear (checked daily). Each problem is announced once until it clears. Use <em>Send test</em> to confirm they reach your desktop.</li>
                    <li><strong>Local service credentials</strong> &mdash; the S3 storage console's URL, username and password and where its bucket data lives on disk, so you're not digging through config files to find them. Shown in full on your own machine (not pictured here, since it's your real credentials).</li>
                    <li><strong>Global Composer credentials</strong> &mdash; for a private Composer package repository (e.g. a licensed package), so <span class="font-mono">composer install/update</span> can authenticate for every project. A single project can use a different account or license for the same host; see <em>Composer credentials</em> under <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('project-settings')">Project settings</button>. They're mirrored to Composer's own <span class="font-mono">auth.json</span> with a snapshot after every change, so if either copy is lost the other rebuilds it automatically (checked every 5 minutes).</li>
                    <li><strong>Dashboard backup</strong> &mdash; see <button type="button" class="text-blue-500 hover:underline" wire:click="setSection('backups')">Backups &amp; recovery</button>.</li>
                </ul>

            @elseif($section === 'backups')
                <h2 class="text-lg font-medium">Backups &amp; recovery</h2>
                <h3 class="font-medium">A project's own database</h3>
                <p>From Project settings &rarr; Database backups: <strong>Backup now</strong>, restore any
                    previous backup, import one from a file, or download it. A backup is also taken automatically
                    right before you switch that project's database driver, and daily if its "Auto backup" flag
                    is on (the default) &mdash; the newest 7 are kept.</p>
                {!! $img('settings-dbbackups', 'A project\'s Database backups panel') !!}
                <h3 class="font-medium">The dashboard's own data</h3>
                <p>The dashboard's list of sites, saved tokens and settings live in one file, backed up
                    automatically every day and before every deploy (the newest 14 are kept). On the Settings page,
                    <em>Back up now</em> takes one straight away, and every backup has a <em>Restore</em> button. Restoring
                    first saves what the dashboard holds right now, listed under <em>Before a restore</em>, so a restore can
                    always be undone the same way. It waits for anything that is writing to the dashboard's database to finish,
                    checks the backup is intact before using it, and takes effect on the next page load. Reload the page afterwards.</p>
                <p>If the dashboard itself won't open, restore from a terminal instead. From <span class="font-mono">~/.ldev/app</span>:</p>
                <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded p-3 overflow-x-auto font-mono">php artisan ldev:restore-dashboard          # lists backups
php artisan ldev:restore-dashboard 20260921-154548   # restores one
systemctl --user restart ldev-dashboard.service      # then restart</pre>
                {!! $img('settings-dashboardbackup', 'The Settings page\'s Dashboard backup panel, listing backups with a Restore button on each') !!}
                <h3 class="font-medium">TLS certificates</h3>
                <p>Renew themselves automatically once a day if they're getting close to expiry &mdash; nothing to do.</p>

            @elseif($section === 'troubleshooting')
                <h2 class="text-lg font-medium">Troubleshooting</h2>
                <ul class="list-disc pl-5 space-y-2">
                    <li>
                        <strong>The dashboard shows a 403.</strong> Your browser's saved token cookie doesn't
                        match <span class="font-mono">~/.config/ldev/token</span>. You don't need the dashboard to
                        fix it: open <strong>Linux Dev</strong> from the applications menu (it passes the current
                        token and your browser saves it again), or run one of these in a terminal:
                        <pre class="mt-1 rounded bg-gray-100 dark:bg-gray-900 p-2 text-xs font-mono whitespace-pre-wrap">~/.ldev/scripts/launch-dashboard.sh</pre>
                        <pre class="mt-1 rounded bg-gray-100 dark:bg-gray-900 p-2 text-xs font-mono whitespace-pre-wrap">xdg-open "http://127.0.0.1:8090/?token=$(cat ~/.config/ldev/token)"</pre>
                        If the token file is missing, or you want a new token (for example if it leaked), make a new
                        one first, then use the launcher again:
                        <pre class="mt-1 rounded bg-gray-100 dark:bg-gray-900 p-2 text-xs font-mono whitespace-pre-wrap">rm -f ~/.config/ldev/token
cd ~/.ldev/app &amp;&amp; php artisan ldev:generate-token</pre>
                        While you still have access, <em>Regenerate</em> on the Settings page does the same and keeps
                        your browser signed in.
                    </li>
                    <li>
                        <strong>A site returns 502.</strong> Its PHP-FPM pool isn't running &mdash; check that
                        project's PHP version on the PHP Versions page and start it if it's stopped.
                    </li>
                    <li>
                        <strong>A <span class="font-mono">.test</span> domain says "Server not found."</strong>
                        dnsmasq needs to be running and to have been restarted since it was last configured &mdash;
                        check it on the Services page. As a fallback for one specific hostname, add it under that
                        project's Project settings &rarr; Local hosts.
                    </li>
                    <li>
                        <strong>Adding/editing a Custom background job is rejected.</strong> That's Supervisor's
                        own parser catching a real problem in the command &mdash; read the error message, it names
                        exactly what's wrong (nothing is written until it's valid).
                    </li>
                    <li>
                        <strong>S3 storage.</strong> Linux Dev uses RustFS for local S3 storage. Earlier versions used MinIO, whose builds are no longer published; updating moves your existing MinIO buckets into RustFS once, with the same address, username and password, so projects keep working unchanged. Each bucket is checked after the move, then MinIO and its data are removed. If anything fails, MinIO's data is left untouched and the next update tries again.
                    </li>
                    <li>
                        <strong>Something else went wrong.</strong> Check the Logs page first &mdash; the
                        dashboard's own log and the affected project's application log are usually the fastest way
                        to see the real error.
                    </li>
                </ul>
            @endif
        </div>
    </div>
</div>
