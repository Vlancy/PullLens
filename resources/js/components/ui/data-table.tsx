import * as React from 'react';
import { cn } from '@/lib/utils';

type DataTableProps = React.ComponentProps<'table'> & {
    /** Classes for the scroll container rather than the table itself. */
    containerClassName?: string;
};

/**
 * A wide data table that scrolls horizontally instead of being squeezed.
 *
 * The report tables carry seven to ten columns. On a phone there is no honest way
 * to fit them: without a floor on the table width the browser crushes every column
 * into a two-character sliver rather than producing a scrollbar, so the numbers
 * stop being readable while still technically being on screen.
 *
 * The floor lives here (`min-w-3xl`, overridable through `className` because
 * tailwind-merge resolves the conflict) and the container owns the scrolling.
 * `overscroll-x-contain` stops a sideways swipe inside the table from also
 * dragging the page, which on iOS otherwise triggers the back gesture. Header
 * cells are kept on one line so a narrow column cannot stack its label
 * vertically and push every row out of alignment.
 */
export function DataTable({
    className,
    containerClassName,
    ...props
}: DataTableProps) {
    return (
        <div
            className={cn(
                'w-full overflow-x-auto overscroll-x-contain',
                containerClassName,
            )}
        >
            <table
                className={cn(
                    'w-full min-w-3xl text-sm [&_th]:whitespace-nowrap',
                    className,
                )}
                {...props}
            />
        </div>
    );
}

export default DataTable;
