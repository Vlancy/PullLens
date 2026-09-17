import { MARK_SRC } from '@/lib/branding';

export default function AppLogo() {
    return (
        <>
            <img
                src={MARK_SRC}
                alt=""
                aria-hidden
                className="size-8 dark:invert"
            />
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    PullLens
                </span>
            </div>
        </>
    );
}
