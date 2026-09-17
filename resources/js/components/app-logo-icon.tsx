import { MARK_SRC } from '@/lib/branding';
import { cn } from '@/lib/utils';

export default function AppLogoIcon({ className }: { className?: string }) {
    return (
        <img
            src={MARK_SRC}
            alt="PullLens"
            // The mark is black on transparency, so without this it disappears
            // against the dark theme - which is what the login screen uses.
            className={cn('dark:invert', className)}
        />
    );
}
