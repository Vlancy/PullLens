import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    BarChart3,
    BookOpen,
    Bot,
    Bug,
    CircleSlash,
    Coins,
    FileText,
    GitPullRequest,
    KeyRound,
    ListChecks,
    Lock,
    MessageSquareReply,
    Radar,
    ServerCog,
    ShieldCheck,
    SlidersHorizontal,
    Sparkles,
    Undo2,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard, login } from '@/routes';

/**
 * Screenshots and recordings are the ones the user guide already ships, served
 * through the /docs route (see DocsController). Keeping one copy means a rebuilt
 * guide and the landing page can never show different versions of the same screen.
 */
const SHOTS = '/docs/images';

/** Where the guide lives once the application is serving it. */
const DOCS_URL = '/docs';

const SOURCE_URL = 'https://github.com/Vlancy/PullLens';

const CONTACT_URL = 'https://vlancy.com/contact';

type Shot = {
    src: string;
    alt: string;
    width: number;
    height: number;
};

type DeepDive = {
    eyebrow: string;
    icon: ComponentType<{ className?: string }>;
    title: string;
    body: string;
    points: string[];
    shot: Shot;
};

/**
 * The long-form feature tour. Each entry becomes one alternating text/screenshot
 * band, in the order someone actually meets the product: the review first, then
 * what the review leaves behind, then what the data adds up to.
 */
const deepDives: DeepDive[] = [
    {
        eyebrow: 'Review',
        icon: Bot,
        title: 'Every pull request reviewed, properly, every time',
        body: 'PullLens watches your repositories and reviews each pull request as it opens, hunting for the things that actually hurt in production. It posts a summary carrying the risk verdict and a table of findings, then comments on the exact lines that introduced them, one comment per finding, each with the severity, the category, why it matters and a fix you can read in ten seconds.',
        points: [
            'SQL injection, missing authorization, leaked secrets, race conditions, N+1 and unbounded queries',
            'Reply to any comment and it answers in the thread with the whole review still in context',
            'Confirm a fix and the finding closes itself, so the dashboard and the conversation never drift apart',
            'It will not comment on your brace style: a reviewer that cries wolf gets muted, and then it catches nothing',
        ],
        shot: {
            src: `${SHOTS}/pr-review-comment.jpg`,
            alt: 'PullLens posting a critical finding as an inline pull request comment, a developer replying, and PullLens answering in the thread',
            width: 1400,
            height: 1365,
        },
    },
    {
        eyebrow: 'Findings',
        icon: Radar,
        title: 'Nothing is flagged and then quietly forgotten',
        body: 'Every finding lands in one place, grouped by severity, category, repository and author, and stays there until somebody closes it with a reason: fixed, acknowledged, or false positive. "We will deal with it later" stops being a feeling and becomes a number on a page.',
        points: [
            'Filter by severity, category, repository, developer or free text',
            'Resolve in bulk, with the resolution reason recorded against each finding',
            'Resolution rate and open critical counts tracked over time',
            'Every finding links straight back to the line and the pull request it came from',
        ],
        shot: {
            src: `${SHOTS}/findings.jpg`,
            alt: 'The findings page showing the severity breakdown, filters and a list of open security issues',
            width: 1553,
            height: 791,
        },
    },
    {
        eyebrow: 'Delivery',
        icon: ListChecks,
        title: 'Know who shipped what, without asking anyone',
        body: 'This is the part other review tools do not do. From the same review, PullLens works out what the pull request actually delivered, as tasks a human would recognise. Not "14 commits". Instead: added multi-currency support to checkout, feature, roughly twelve hours, attributed to the developer whose commits carried the change and linked to the code that delivered it.',
        points: [
            'Tasks attributed to the commit author who did the work, not whoever opened the pull request',
            'First-time-right rate: was it correct on the first pass, or did it come back two weeks later?',
            'Tasks link to each other as fixes, extends and reverts, so you can follow the whole thread',
            'Issue keys such as PROJ-451 are picked up from branch names and titles and linked to your tracker',
        ],
        shot: {
            src: `${SHOTS}/tasks-board.jpg`,
            alt: 'The tasks board with search, filters and the first-time-right rate',
            width: 1553,
            height: 791,
        },
    },
    {
        eyebrow: 'Reports',
        icon: BarChart3,
        title: 'The numbers leadership asks for, without a spreadsheet',
        body: 'Throughput, code volume, review outcomes, rework, cycle time and merge velocity, per developer, per repository, over any period. Every report is built from reviews that already happened, so nobody has to fill anything in for the data to exist.',
        points: [
            'Team, repository, commit quality, daily activity and daily effort reports',
            'Per-developer profiles with a contribution calendar and delivered work',
            'Rework made visible, so the silent tax on your roadmap has a size',
            'A conversation starter, not a scoreboard: the data shows where to look, not what to conclude',
        ],
        shot: {
            src: `${SHOTS}/report-team.jpg`,
            alt: 'The team performance report comparing developers across throughput, review outcomes and rework',
            width: 1539,
            height: 784,
        },
    },
    {
        eyebrow: 'Cost',
        icon: Coins,
        title: 'Know exactly what the AI costs you',
        body: 'Every provider call is logged with the tokens, the model, the repository and what it cost. You bring your own API key and pay the provider directly at cost. PullLens takes nothing and adds nothing.',
        points: [
            'Spend broken down by repository, model and provider',
            'Token counts per review, so an expensive repository is obvious',
            'No per-seat billing, no usage tier, no invoice from us',
            'Switch provider or model per repository whenever the economics change',
        ],
        shot: {
            src: `${SHOTS}/report-ai-usage.jpg`,
            alt: 'The AI usage report showing token spend broken down by model and repository',
            width: 1539,
            height: 784,
        },
    },
    {
        eyebrow: 'Control',
        icon: SlidersHorizontal,
        title: 'Tuned per repository, not per company',
        body: 'A legacy service and a greenfield API do not want the same reviewer. Each repository gets its own provider, model, review language, tone, intensity, watched branches and merge behaviour, and each automatic action is a switch you turn on yourself.',
        points: [
            'Inline comments, threaded replies, pull request labels, generated titles and descriptions',
            'Approving reviews and auto-merge once the reviewer is satisfied, if you want them',
            'Watched branches, so a noisy fork branch never burns provider credit',
            'Twelve providers supported, including a local Ollama for code that may not leave the network',
        ],
        shot: {
            src: `${SHOTS}/guide/repo-settings-reviews.jpg`,
            alt: 'Repository settings showing the review engine, tone, intensity and automatic action switches',
            width: 1562,
            height: 784,
        },
    },
    {
        eyebrow: 'Assistant',
        icon: Sparkles,
        title: 'Ask your own data a question',
        body: 'The built-in assistant queries the live PullLens database on your behalf. Ask which repository produces the most rework this quarter, or who reviewed the most pull requests last month, and it answers from your own records rather than a guess.',
        points: [
            'Answers grounded in your repositories, reviews, findings and tasks',
            'Uses the same provider and key you already configured',
            'Suggested questions to start from, then follow up in plain English',
            'Nothing is sent anywhere your reviews are not already going',
        ],
        shot: {
            src: `${SHOTS}/guide/assistant.jpg`,
            alt: 'The PullLens assistant answering a question about engineering metrics',
            width: 1562,
            height: 784,
        },
    },
    {
        eyebrow: 'Access',
        icon: Users,
        title: 'Self-hosted should not mean unlocked',
        body: 'Public sign-up does not exist: there is no registration endpoint to find. Accounts are created by an administrator, and roles are edited in the interface with per-repository scoping, so a contractor sees only the repositories you grant them.',
        points: [
            'Roles and granular permissions, editable without a deploy',
            'Per-repository access scoping for contractors and cross-team work',
            'Two-factor authentication and passkeys',
            'Provider credentials and webhook secrets encrypted at rest, webhooks rejected without a valid signature',
        ],
        shot: {
            src: `${SHOTS}/guide/roles.jpg`,
            alt: 'The roles and permissions screen with granular per-role permission toggles',
            width: 1562,
            height: 784,
        },
    },
];

/**
 * The short capability grid. Everything here is either covered in more depth
 * above or too small to justify its own band, but still worth naming.
 */
const capabilities = [
    {
        icon: ShieldCheck,
        title: 'Security caught pre-merge',
        description:
            'Injection, broken auth and OWASP-class flaws flagged on the exact lines that introduced them.',
    },
    {
        icon: KeyRound,
        title: 'Secrets and env protection',
        description:
            'Committed credentials, tokens and .env values stopped before they reach a protected branch.',
    },
    {
        icon: MessageSquareReply,
        title: 'Answers in the thread',
        description:
            'Follow-up questions answered on the pull request, so context stays where the work happens.',
    },
    {
        icon: Sparkles,
        title: 'AI versus human authorship',
        description:
            'See how much of each change was machine-generated, and govern AI adoption with evidence.',
    },
    {
        icon: Activity,
        title: 'Code quality, trended',
        description:
            'Maintainability and complexity tracked per repository, so "are we getting better?" has an answer.',
    },
    {
        icon: GitPullRequest,
        title: 'Delivery analytics',
        description:
            'Cycle time, review throughput and merge velocity by pull request and by team.',
    },
    {
        icon: Bug,
        title: 'Defect intelligence',
        description:
            'Which changes are most likely to regress, and where the defects originate.',
    },
    {
        icon: Undo2,
        title: 'Rework, made visible',
        description:
            'How much work gets sent back, per team, so hidden rework can be removed.',
    },
    {
        icon: FileText,
        title: 'Structured pull request context',
        description:
            'A walkthrough, the detected stack, skipped files, suggested labels, a risk verdict and diagrams.',
    },
];

/**
 * Why a process beats a person pasting a diff into a chat window. This is the
 * objection every engineering lead raises first, so it gets answered before the
 * feature tour rather than buried underneath it.
 */
const versusDirectAi = [
    {
        icon: CircleSlash,
        title: 'A prompt is not a process',
        direct: 'Someone has to remember to paste the diff, and they paste the part they were already worried about.',
        pulllens:
            'Every pull request is reviewed on open and on every update, with the full diff and the repository context, whether anyone remembers or not.',
    },
    {
        icon: FileText,
        title: 'A chat leaves nothing behind',
        direct: 'The answer disappears with the tab. Nothing is tracked, nothing is counted, nothing can be audited later.',
        pulllens:
            'Every finding is kept until it is closed with a reason, and every review becomes delivery and quality data you can open six months later.',
    },
    {
        icon: Lock,
        title: 'Consumer accounts are not your perimeter',
        direct: 'Pasting source into a personal AI account moves your code into someone else’s retention policy, invisibly.',
        pulllens:
            'One outbound call, to the provider you chose, with the key you own, from infrastructure you control. Or none at all, with a local Ollama.',
    },
];

const steps = [
    {
        title: 'Connect once',
        description:
            'Authorize PullLens with GitHub and choose the repositories and branches that matter. Minutes to value, not a quarter-long rollout.',
    },
    {
        title: 'Open a pull request',
        description:
            'Every pull request is reviewed automatically by your configured AI provider. Nothing for engineers to install, nothing to opt into.',
    },
    {
        title: 'Act on what matters',
        description:
            'Security, quality and risk findings land inline, and the reviewer answers questions in the thread. Decisions get faster, not noisier.',
    },
    {
        title: 'Prove the outcome',
        description:
            'Every review becomes a metric on quality, defects and team performance, so you can show the impact rather than claim it.',
    },
];

const outcomes = [
    {
        stat: '100%',
        label: 'of pull requests reviewed',
        sub: 'Consistent scrutiny on every change, with no pull request slipping through on a busy Friday.',
    },
    {
        stat: '0',
        label: 'lines of code leave your servers',
        sub: 'Fully self-hosted. Your source, your infrastructure, your control.',
    },
    {
        stat: '24/7',
        label: 'review coverage',
        sub: 'No queue, no bottleneck, no waiting on the one engineer who knows that file.',
    },
];

function GitHubIcon({ className }: { className?: string }) {
    return (
        <svg
            aria-hidden="true"
            className={className}
            fill="currentColor"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path d="M12 2C6.48 2 2 6.58 2 12.26c0 4.52 2.87 8.36 6.84 9.72.5.1.68-.22.68-.5v-1.74c-2.78.62-3.37-1.37-3.37-1.37-.45-1.18-1.11-1.5-1.11-1.5-.91-.64.07-.63.07-.63 1 .07 1.53 1.06 1.53 1.06.89 1.56 2.34 1.11 2.91.85.09-.66.35-1.11.63-1.37-2.22-.26-4.56-1.14-4.56-5.07 0-1.12.39-2.03 1.03-2.75-.1-.26-.45-1.3.1-2.71 0 0 .84-.28 2.75 1.05A9.3 9.3 0 0 1 12 6.98c.85 0 1.7.12 2.5.34 1.91-1.33 2.75-1.05 2.75-1.05.55 1.41.2 2.45.1 2.71.64.72 1.03 1.63 1.03 2.75 0 3.94-2.34 4.81-4.57 5.07.36.32.68.94.68 1.9v2.78c0 .28.18.6.69.5A10.22 10.22 0 0 0 22 12.26C22 6.58 17.52 2 12 2Z" />
        </svg>
    );
}

/**
 * A screenshot in a frame. Intrinsic dimensions are always passed so the browser
 * reserves the right space before the image arrives and the page does not jump
 * while someone is reading it.
 */
function Screenshot({ shot, className }: { shot: Shot; className?: string }) {
    return (
        <div
            className={cn(
                'overflow-hidden rounded-xl border border-border bg-muted/30 shadow-sm',
                className,
            )}
        >
            <img
                src={shot.src}
                alt={shot.alt}
                width={shot.width}
                height={shot.height}
                loading="lazy"
                decoding="async"
                className="block h-auto w-full"
            />
        </div>
    );
}

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head>
                <title>
                    PullLens - AI Code Review That Ships High-Quality Code
                </title>
                <meta
                    name="description"
                    content="Self-hosted AI code reviewer that puts a tireless, senior-grade reviewer on every pull request - catching security, quality, and risk before merge. Source-available. Your infrastructure. Your control."
                />
                <meta
                    name="keywords"
                    content="AI code review, pull request review, self-hosted code review, automated code review, security scanning, code quality, source-available, GitHub app"
                />
                <meta name="robots" content="index, follow" />

                {/*
                    Open Graph and Twitter cards are rendered server-side by
                    resources/views/partials/head.blade.php so that every page -
                    including the error pages, which never boot the SPA - carries
                    them, and so the image URL is absolute. Duplicating them here
                    would emit two of each tag into the head.
                */}
            </Head>

            <div className="min-h-screen bg-background text-foreground">
                {/* Nav */}
                <header className="sticky top-0 z-40 border-b border-border/60 bg-background/80 backdrop-blur">
                    <nav className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:px-6 sm:py-4">
                        <div className="flex min-w-0 items-center gap-2.5">
                            <img
                                src="/favicon.png"
                                alt="PullLens"
                                width={32}
                                height={32}
                                className="size-8 dark:invert"
                            />
                            <span className="truncate text-base font-semibold tracking-tight">
                                PullLens
                            </span>
                        </div>

                        <div className="flex items-center gap-2 sm:gap-3">
                            <a
                                href={SOURCE_URL}
                                target="_blank"
                                rel="noreferrer"
                                className="hidden items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground sm:flex"
                            >
                                <GitHubIcon className="size-4" />
                                Source
                            </a>
                            <Button asChild variant="outline" size="sm">
                                <a href={DOCS_URL}>
                                    <BookOpen className="size-4" />
                                    Docs
                                </a>
                            </Button>
                            {auth.user ? (
                                <Button asChild size="sm">
                                    <Link href={dashboard()}>
                                        Dashboard
                                        <ArrowRight className="size-4" />
                                    </Link>
                                </Button>
                            ) : (
                                <Button asChild size="sm">
                                    <Link href={login()}>Log in</Link>
                                </Button>
                            )}
                        </div>
                    </nav>
                </header>

                {/* Hero */}
                <section className="relative overflow-hidden">
                    <div className="pointer-events-none absolute inset-x-0 -top-40 -z-10 mx-auto h-[28rem] max-w-4xl bg-gradient-to-b from-primary/15 to-transparent blur-3xl" />
                    <div className="mx-auto max-w-6xl px-4 py-14 text-center sm:px-6 sm:py-20 lg:py-28">
                        <div className="mx-auto mb-6 inline-flex max-w-full items-center gap-2 rounded-full border border-border bg-muted/40 px-4 py-1.5 text-xs font-medium text-muted-foreground">
                            <ServerCog className="size-3.5 shrink-0" />
                            <span className="truncate">
                                Self-hosted · Source-available · Yours to
                                control
                            </span>
                        </div>
                        <h1 className="mx-auto max-w-3xl text-3xl font-semibold tracking-tight text-balance sm:text-5xl lg:text-6xl">
                            AI code review that ships{' '}
                            <span className="text-primary">
                                high-quality code
                            </span>
                        </h1>
                        <p className="mx-auto mt-5 max-w-2xl text-base text-pretty text-muted-foreground sm:mt-6 sm:text-lg">
                            PullLens puts a tireless, senior-grade AI reviewer
                            on every pull request, catching security, quality
                            and risk before merge, and turning each review into
                            hard evidence of how your teams perform. All on
                            infrastructure you control.
                        </p>
                        <div className="mt-8 flex flex-col items-stretch justify-center gap-3 sm:mt-9 sm:flex-row sm:items-center">
                            <Button asChild size="lg">
                                <a
                                    href={CONTACT_URL}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Contact us
                                    <ArrowRight className="size-4" />
                                </a>
                            </Button>
                            <Button asChild size="lg" variant="outline">
                                <a href={DOCS_URL}>
                                    <BookOpen className="size-4" />
                                    Read the docs
                                </a>
                            </Button>
                            <Button asChild size="lg" variant="ghost">
                                <a
                                    href={SOURCE_URL}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <GitHubIcon className="size-4" />
                                    Get the source
                                </a>
                            </Button>
                        </div>
                        <p className="mt-4 inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Lock className="size-3.5 shrink-0" />
                            Invite-only access · provisioned by your
                            administrator
                        </p>

                        {/* Product tour. Eager, because it is the one image above the fold. */}
                        <div className="mx-auto mt-12 max-w-4xl overflow-hidden rounded-xl border border-border bg-card shadow-lg sm:mt-14">
                            <img
                                src={`${SHOTS}/tour.gif`}
                                alt="A tour of PullLens: the dashboard, the findings page and the delivered tasks board"
                                width={1000}
                                height={509}
                                decoding="async"
                                className="block h-auto w-full"
                            />
                        </div>
                    </div>
                </section>

                {/* Outcomes / trust band */}
                <section className="border-y border-border/60 bg-muted/20">
                    <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 sm:py-14 md:grid-cols-3">
                        {outcomes.map((item) => (
                            <div key={item.label} className="text-center">
                                <div className="text-4xl font-semibold tracking-tight text-primary">
                                    {item.stat}
                                </div>
                                <div className="mt-1 text-sm font-medium">
                                    {item.label}
                                </div>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {item.sub}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Why not just use an AI chat */}
                <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
                    <div className="mx-auto max-w-2xl text-center">
                        <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                            Your team already has AI. This is what it still
                            cannot do.
                        </h2>
                        <p className="mt-4 text-muted-foreground">
                            Pasting a diff into a chat window is a personal
                            habit. Catching problems before they merge is a
                            system, and a system has to run whether anyone is
                            thinking about it or not.
                        </p>
                    </div>

                    <div className="mt-10 grid gap-5 md:grid-cols-3">
                        {versusDirectAi.map((item) => (
                            <div
                                key={item.title}
                                className="flex flex-col rounded-xl border border-border bg-card p-6"
                            >
                                <div className="mb-4 flex size-11 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <item.icon className="size-5" />
                                </div>
                                <h3 className="text-base font-semibold">
                                    {item.title}
                                </h3>
                                <p className="mt-3 text-sm leading-relaxed text-muted-foreground">
                                    <span className="font-medium text-foreground/70">
                                        Asking an AI directly:{' '}
                                    </span>
                                    {item.direct}
                                </p>
                                <p className="mt-3 border-t border-border pt-3 text-sm leading-relaxed">
                                    <span className="font-medium text-primary">
                                        With PullLens:{' '}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {item.pulllens}
                                    </span>
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Feature tour */}
                <section
                    id="features"
                    className="border-t border-border/60 bg-muted/20"
                >
                    <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
                        <div className="mx-auto max-w-2xl text-center">
                            <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                                Everything it does, screen by screen
                            </h2>
                            <p className="mt-4 text-muted-foreground">
                                Automated review, security, delivery evidence
                                and the accountability data your leadership has
                                been asking for, in one platform you own end to
                                end.
                            </p>
                        </div>

                        <div className="mt-14 space-y-16 sm:space-y-24">
                            {deepDives.map((dive, index) => (
                                <div
                                    key={dive.title}
                                    className="grid items-center gap-8 lg:grid-cols-2 lg:gap-14"
                                >
                                    <div
                                        className={cn(
                                            index % 2 === 1 && 'lg:order-2',
                                        )}
                                    >
                                        <div className="inline-flex items-center gap-2 rounded-full border border-border bg-background px-3 py-1 text-xs font-medium text-muted-foreground">
                                            <dive.icon className="size-3.5 text-primary" />
                                            {dive.eyebrow}
                                        </div>
                                        <h3 className="mt-4 text-xl font-semibold tracking-tight text-balance sm:text-2xl">
                                            {dive.title}
                                        </h3>
                                        <p className="mt-4 text-sm leading-relaxed text-muted-foreground sm:text-base">
                                            {dive.body}
                                        </p>
                                        <ul className="mt-5 space-y-2.5">
                                            {dive.points.map((point) => (
                                                <li
                                                    key={point}
                                                    className="flex items-start gap-2.5 text-sm text-muted-foreground"
                                                >
                                                    <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary" />
                                                    <span>{point}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>

                                    <Screenshot
                                        shot={dive.shot}
                                        className={cn(
                                            index % 2 === 1 && 'lg:order-1',
                                        )}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                {/* Capability grid */}
                <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
                    <div className="mx-auto max-w-2xl text-center">
                        <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                            And the rest of it
                        </h2>
                        <p className="mt-4 text-muted-foreground">
                            Smaller things that still change how a week goes.
                        </p>
                    </div>

                    <div className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {capabilities.map((feature) => (
                            <div
                                key={feature.title}
                                className="group rounded-xl border border-border bg-card p-6 transition-colors hover:border-primary/40 hover:bg-accent/30"
                            >
                                <div className="mb-4 flex size-11 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <feature.icon className="size-5" />
                                </div>
                                <h3 className="text-base font-semibold">
                                    {feature.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                    {feature.description}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Data sovereignty */}
                <section className="mx-auto max-w-6xl px-4 pb-4 sm:px-6">
                    <div className="grid items-center gap-8 rounded-2xl border border-border bg-card p-6 sm:p-10 lg:grid-cols-2 lg:p-12">
                        <div>
                            <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-border bg-muted/40 px-3 py-1 text-xs font-medium text-muted-foreground">
                                <Lock className="size-3.5 shrink-0" />
                                Your code never leaves the building
                            </div>
                            <h2 className="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
                                Built for teams who cannot outsource their trust
                            </h2>
                            <p className="mt-4 text-muted-foreground">
                                SaaS reviewers ask you to ship your most
                                valuable asset, your source, to someone
                                else&rsquo;s cloud. PullLens does not. It runs
                                entirely on your infrastructure, with no
                                per-seat billing and no data leaving your
                                perimeter. Security, legal and finance all get
                                the answer they want.
                            </p>
                        </div>
                        <ul className="grid gap-4">
                            {[
                                'Fully self-hosted: your servers, your keys, your control',
                                'No source code or telemetry sent to a third party',
                                'Bring your own AI provider and policies',
                                'Source-available and auditable, top to bottom',
                            ].map((point) => (
                                <li
                                    key={point}
                                    className="flex items-start gap-3 rounded-lg border border-border bg-background/50 p-4 text-sm"
                                >
                                    <ShieldCheck className="mt-0.5 size-5 shrink-0 text-primary" />
                                    <span>{point}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                {/* How it works */}
                <section className="mt-16 border-t border-border/60 bg-muted/20 sm:mt-20">
                    <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-20">
                        <div className="mx-auto max-w-2xl text-center">
                            <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                                Live in minutes. Compounding for years.
                            </h2>
                            <p className="mt-4 text-muted-foreground">
                                Configure once. PullLens does the rest on every
                                review, and the insights only get sharper over
                                time.
                            </p>
                        </div>

                        <div className="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                            {steps.map((step, index) => (
                                <div key={step.title} className="relative">
                                    <div className="mb-4 flex size-9 items-center justify-center rounded-full border border-primary/30 bg-background text-sm font-semibold text-primary">
                                        {index + 1}
                                    </div>
                                    <h3 className="text-base font-semibold">
                                        {step.title}
                                    </h3>
                                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                        {step.description}
                                    </p>
                                </div>
                            ))}
                        </div>

                        <div className="mt-12 grid items-center gap-8 lg:grid-cols-2 lg:gap-14">
                            <Screenshot
                                shot={{
                                    src: `${SHOTS}/guide/setup-ai-provider.gif`,
                                    alt: 'Connecting an AI provider in PullLens, from choosing the provider to saving the credentials',
                                    width: 980,
                                    height: 492,
                                }}
                            />
                            <div>
                                <h3 className="text-xl font-semibold tracking-tight text-balance sm:text-2xl">
                                    Setup is a form, not a project
                                </h3>
                                <p className="mt-4 text-sm leading-relaxed text-muted-foreground sm:text-base">
                                    One installer script brings up the
                                    application, the queue worker, PostgreSQL,
                                    Redis and mail, runs the migrations and
                                    prints your login URL. Then you paste an API
                                    key, authorize GitHub and pick the
                                    repositories to watch. The illustrated guide
                                    walks through every screen and every setting
                                    one by one.
                                </p>
                                <div className="mt-6 flex flex-wrap gap-3">
                                    <Button asChild>
                                        <a href={DOCS_URL}>
                                            <BookOpen className="size-4" />
                                            Open the documentation
                                        </a>
                                    </Button>
                                    <Button asChild variant="outline">
                                        <a
                                            href={`${DOCS_URL}/guide/installation.html`}
                                        >
                                            Installation guide
                                            <ArrowRight className="size-4" />
                                        </a>
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* CTA */}
                <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
                    <div className="relative overflow-hidden rounded-2xl border border-border bg-card px-5 py-12 text-center sm:px-6 sm:py-16">
                        <div className="pointer-events-none absolute inset-x-0 -top-24 -z-10 mx-auto h-64 max-w-xl bg-gradient-to-b from-primary/20 to-transparent blur-3xl" />
                        <h2 className="mx-auto max-w-2xl text-2xl font-semibold tracking-tight text-balance sm:text-4xl">
                            Every merge without PullLens is a bet you do not
                            have to make
                        </h2>
                        <p className="mx-auto mt-4 max-w-xl text-muted-foreground">
                            Give your team an expert reviewer that never sleeps,
                            never rushes and never leaks your code. Own the
                            entire pipeline, on your terms.
                        </p>
                        <div className="mt-8 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center">
                            <Button asChild size="lg">
                                <a
                                    href={CONTACT_URL}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Contact us
                                    <ArrowRight className="size-4" />
                                </a>
                            </Button>
                            <Button asChild size="lg" variant="outline">
                                <a href={DOCS_URL}>
                                    <BookOpen className="size-4" />
                                    Read the docs
                                </a>
                            </Button>
                            <Button asChild size="lg" variant="ghost">
                                <a
                                    href={SOURCE_URL}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <GitHubIcon className="size-4" />
                                    Get the source
                                </a>
                            </Button>
                        </div>
                    </div>
                </section>

                {/* Footer */}
                <footer className="border-t border-border/60">
                    <div className="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-8 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                        <div className="flex items-center gap-2.5">
                            <img
                                src="/favicon.png"
                                alt="PullLens"
                                width={28}
                                height={28}
                                className="size-7 dark:invert"
                            />
                            <span className="text-sm font-medium">
                                <a
                                    href="https://pulllens.com"
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-medium text-foreground/80 underline-offset-4 transition-colors hover:text-foreground hover:underline"
                                >
                                    PullLens
                                </a>{' '}
                                · AI code review that ships high-quality code
                            </span>
                        </div>

                        <nav className="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-muted-foreground">
                            <a
                                href={DOCS_URL}
                                className="transition-colors hover:text-foreground"
                            >
                                Documentation
                            </a>
                            <a
                                href={`${DOCS_URL}/guide/installation.html`}
                                className="transition-colors hover:text-foreground"
                            >
                                Install
                            </a>
                            <a
                                href={`${DOCS_URL}/guide/troubleshooting.html`}
                                className="transition-colors hover:text-foreground"
                            >
                                Troubleshooting
                            </a>
                            <a
                                href={SOURCE_URL}
                                target="_blank"
                                rel="noreferrer"
                                className="transition-colors hover:text-foreground"
                            >
                                Source
                            </a>
                            <a
                                href={CONTACT_URL}
                                target="_blank"
                                rel="noreferrer"
                                className="transition-colors hover:text-foreground"
                            >
                                Contact
                            </a>
                        </nav>

                        <p className="text-xs text-muted-foreground">
                            © {new Date().getFullYear()}{' '}
                            <a
                                href="https://vlancy.com"
                                target="_blank"
                                rel="noreferrer"
                                className="font-medium text-foreground/80 underline-offset-4 transition-colors hover:text-foreground hover:underline"
                            >
                                Vlancy LTD
                            </a>{' '}
                            All rights reserved.
                        </p>
                    </div>
                </footer>
            </div>
        </>
    );
}
