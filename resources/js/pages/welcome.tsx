import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Bot,
    Bug,
    GitPullRequest,
    Github,
    KeyRound,
    Lock,
    MessageSquareReply,
    ServerCog,
    ShieldCheck,
    Sparkles,
    Undo2,
    Users,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const features = [
    {
        icon: Bot,
        title: 'Autonomous AI reviewer',
        description:
            'A senior-level reviewer on every pull request, 24/7. It judges risk — security, performance, reliability — not whitespace, so your engineers stop rubber-stamping and start shipping.',
    },
    {
        icon: ShieldCheck,
        title: 'Security caught pre-merge',
        description:
            'Injection, broken auth, and OWASP-class flaws are flagged on the exact lines that introduced them. The vulnerability you never hear about is the one that costs you the most.',
    },
    {
        icon: KeyRound,
        title: 'Secrets & env protection',
        description:
            'A single leaked key can become tomorrow’s breach headline. PullLens stops committed credentials, tokens, and .env values before they reach a protected branch.',
    },
    {
        icon: MessageSquareReply,
        title: 'Answers in the thread',
        description:
            'The reviewer replies to follow-up questions directly on the PR — context stays where the work happens, and no one waits a day for a second opinion.',
    },
    {
        icon: Sparkles,
        title: 'AI vs. human authorship',
        description:
            'Know exactly how much of every change was machine-generated. Govern AI adoption with evidence, and focus human scrutiny where it actually matters.',
    },
    {
        icon: Activity,
        title: 'Code quality, trended',
        description:
            'Maintainability and complexity tracked per repository over time. Replace gut feel with a defensible answer to “is our codebase getting better?”',
    },
    {
        icon: GitPullRequest,
        title: 'Delivery analytics',
        description:
            'Cycle time, review throughput, and merge velocity by PR and by team — the numbers leadership asks for, without a single manual spreadsheet.',
    },
    {
        icon: Bug,
        title: 'Defect intelligence',
        description:
            'See which changes are most likely to introduce regressions and where defects originate — fix the source, not just the symptom.',
    },
    {
        icon: Undo2,
        title: 'Rework, made visible',
        description:
            'Quantify how much work gets sent back to each team. Hidden rework is the silent tax on your roadmap — now you can see it and remove it.',
    },
    {
        icon: Users,
        title: 'Accountability by team',
        description:
            'Compare contribution, review quality, and delivery across teams. Reward the behavior you want more of, with data everyone trusts.',
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
            'Every PR is reviewed automatically by your configured AI provider — no plugins for engineers to remember, nothing to opt into.',
    },
    {
        title: 'Act on what matters',
        description:
            'Security, quality, and risk findings land inline, and the reviewer answers questions in the thread. Decisions get faster, not noisier.',
    },
    {
        title: 'Prove the outcome',
        description:
            'Every review becomes a metric on quality, defects, and team performance — so you can show the impact, not just claim it.',
    },
];

const outcomes = [
    {
        stat: '100%',
        label: 'of pull requests reviewed',
        sub: 'Consistent scrutiny on every change — no PR slips through on a busy Friday.',
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

export default function Welcome() {
    const { auth } = usePage().props;
    const primaryHref = auth.user ? dashboard() : login();

    return (
        <>
            <Head title="PullLens — AI code review that ships high-quality code" />

            <div className="min-h-screen bg-background text-foreground">
                {/* Nav */}
                <header className="sticky top-0 z-40 border-b border-border/60 bg-background/80 backdrop-blur">
                    <nav className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                        <div className="flex items-center gap-2.5">
                            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-primary text-primary-foreground">
                                <AppLogoIcon className="size-5 fill-current" />
                            </div>
                            <span className="text-base font-semibold tracking-tight">
                                PullLens
                            </span>
                        </div>

                        <div className="flex items-center gap-3">
                            <a
                                href="https://github.com/Vlancy/PullLens"
                                target="_blank"
                                rel="noreferrer"
                                className="hidden items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground sm:flex"
                            >
                                <Github className="size-4" />
                                Source
                            </a>
                            {auth.user ? (
                                <Button asChild>
                                    <Link href={dashboard()}>
                                        Dashboard
                                        <ArrowRight className="size-4" />
                                    </Link>
                                </Button>
                            ) : (
                                <Button asChild>
                                    <Link href={login()}>Log in</Link>
                                </Button>
                            )}
                        </div>
                    </nav>
                </header>

                {/* Hero */}
                <section className="relative overflow-hidden">
                    <div className="pointer-events-none absolute inset-x-0 -top-40 -z-10 mx-auto h-[28rem] max-w-4xl bg-gradient-to-b from-primary/15 to-transparent blur-3xl" />
                    <div className="mx-auto max-w-6xl px-6 py-20 text-center sm:py-28">
                        <div className="mx-auto mb-6 inline-flex items-center gap-2 rounded-full border border-border bg-muted/40 px-4 py-1.5 text-xs font-medium text-muted-foreground">
                            <ServerCog className="size-3.5" />
                            Self-hosted · Open source · Yours to control
                        </div>
                        <h1 className="mx-auto max-w-3xl text-balance text-4xl font-semibold tracking-tight sm:text-6xl">
                            AI code review that ships{' '}
                            <span className="text-primary">
                                high-quality code
                            </span>
                        </h1>
                        <p className="mx-auto mt-6 max-w-2xl text-pretty text-lg text-muted-foreground">
                            PullLens puts a tireless, senior-grade AI reviewer on
                            every pull request — catching security, quality, and
                            risk before merge, and turning each review into hard
                            evidence of how your teams perform. All on
                            infrastructure you control.
                        </p>
                        <div className="mt-9 flex flex-wrap items-center justify-center gap-3">
                            <Button asChild size="lg">
                                <Link href={primaryHref}>
                                    {auth.user
                                        ? 'Go to dashboard'
                                        : 'Access your workspace'}
                                    <ArrowRight className="size-4" />
                                </Link>
                            </Button>
                            <Button asChild size="lg" variant="outline">
                                <a
                                    href="https://github.com/Vlancy/PullLens"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Github className="size-4" />
                                    Review the source
                                </a>
                            </Button>
                        </div>
                        <p className="mt-4 inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Lock className="size-3.5" />
                            Invite-only access · provisioned by your administrator
                        </p>
                    </div>
                </section>

                {/* Outcomes / trust band */}
                <section className="border-y border-border/60 bg-muted/20">
                    <div className="mx-auto grid max-w-6xl gap-8 px-6 py-14 sm:grid-cols-3">
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

                {/* Features */}
                <section className="mx-auto max-w-6xl px-6 py-20">
                    <div className="mx-auto max-w-2xl text-center">
                        <h2 className="text-3xl font-semibold tracking-tight">
                            Confidence at the speed of merge
                        </h2>
                        <p className="mt-4 text-muted-foreground">
                            Automated review, security, and the accountability
                            data your leadership has been asking for — in one
                            platform you own end to end.
                        </p>
                    </div>

                    <div className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {features.map((feature) => (
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

                {/* Data sovereignty / authority */}
                <section className="mx-auto max-w-6xl px-6 pb-4">
                    <div className="grid items-center gap-8 rounded-2xl border border-border bg-card p-8 sm:p-12 lg:grid-cols-2">
                        <div>
                            <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-border bg-muted/40 px-3 py-1 text-xs font-medium text-muted-foreground">
                                <Lock className="size-3.5" />
                                Your code never leaves the building
                            </div>
                            <h2 className="text-3xl font-semibold tracking-tight">
                                Built for teams who can’t outsource their trust
                            </h2>
                            <p className="mt-4 text-muted-foreground">
                                SaaS reviewers ask you to ship your most valuable
                                asset — your source — to someone else’s cloud.
                                PullLens doesn’t. It runs entirely on your
                                infrastructure, with no per-seat billing and no
                                data leaving your perimeter. Security, legal, and
                                finance all get the answer they want.
                            </p>
                        </div>
                        <ul className="grid gap-4">
                            {[
                                'Fully self-hosted — your servers, your keys, your control',
                                'No source code or telemetry sent to a third party',
                                'Bring your own AI provider and policies',
                                'Open source and auditable, top to bottom',
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
                <section className="bg-muted/20">
                    <div className="mx-auto max-w-6xl px-6 py-20">
                        <div className="mx-auto max-w-2xl text-center">
                            <h2 className="text-3xl font-semibold tracking-tight">
                                Live in minutes. Compounding for years.
                            </h2>
                            <p className="mt-4 text-muted-foreground">
                                Configure once. PullLens does the rest on every
                                review — and the insights only get sharper over
                                time.
                            </p>
                        </div>

                        <div className="mt-14 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
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
                    </div>
                </section>

                {/* CTA */}
                <section className="mx-auto max-w-6xl px-6 py-24">
                    <div className="relative overflow-hidden rounded-2xl border border-border bg-card px-6 py-16 text-center">
                        <div className="pointer-events-none absolute inset-x-0 -top-24 -z-10 mx-auto h-64 max-w-xl bg-gradient-to-b from-primary/20 to-transparent blur-3xl" />
                        <h2 className="mx-auto max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl">
                            Every merge without PullLens is a bet you don’t have
                            to make
                        </h2>
                        <p className="mx-auto mt-4 max-w-xl text-muted-foreground">
                            Give your team an expert reviewer that never sleeps,
                            never rushes, and never leaks your code. Own the entire
                            pipeline — on your terms.
                        </p>
                        <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
                            <Button asChild size="lg">
                                <Link href={primaryHref}>
                                    {auth.user ? 'Open dashboard' : 'Log in'}
                                    <ArrowRight className="size-4" />
                                </Link>
                            </Button>
                            <Button asChild size="lg" variant="outline">
                                <a
                                    href="https://github.com/Vlancy/PullLens"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Github className="size-4" />
                                    Get the source
                                </a>
                            </Button>
                        </div>
                    </div>
                </section>

                {/* Footer */}
                <footer className="border-t border-border/60">
                    <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-6 py-8 sm:flex-row">
                        <div className="flex items-center gap-2.5">
                            <div className="flex aspect-square size-7 items-center justify-center rounded-md bg-primary text-primary-foreground">
                                <AppLogoIcon className="size-4 fill-current" />
                            </div>
                            <span className="text-sm font-medium">PullLens</span>
                        </div>
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
                            · AI code review · Self-hosted · MIT licensed
                        </p>
                    </div>
                </footer>
            </div>
        </>
    );
}
