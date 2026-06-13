export default function AppLogo() {
    return (
        <>
            <img
                src="/favicon.png"
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
