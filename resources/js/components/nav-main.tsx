import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

/** Where a section's open state is remembered between visits. */
function storageKey(item: NavItem): string {
    return `nav-section:${item.title}`;
}

/** The remembered open state of a section, or null when there is none. */
function readOpen(item: NavItem): boolean | null {
    try {
        const stored = window.localStorage.getItem(storageKey(item));

        return stored === null ? null : stored === '1';
    } catch {
        return null;
    }
}

function writeOpen(item: NavItem, open: boolean): void {
    try {
        window.localStorage.setItem(storageKey(item), open ? '1' : '0');
    } catch {
        // Storage can be unavailable (private mode, blocked site data); the
        // section still toggles, it just is not remembered.
    }
}

function ItemLink({ item }: { item: NavItem }) {
    return item.external ? (
        <a href={item.href as string} target="_blank" rel="noopener noreferrer">
            {item.icon && <item.icon />}
            <span>{item.title}</span>
        </a>
    ) : (
        <Link href={item.href} prefetch>
            {item.icon && <item.icon />}
            <span>{item.title}</span>
        </Link>
    );
}

/**
 * A parent item with children. The label still navigates; the chevron toggles
 * the sub list. A section holding the current page starts open; otherwise it
 * keeps whatever the user last chose, collapsed by default. In icon mode the
 * sidebar hides both the chevron and the sub list.
 */
function NavSection({ item }: { item: NavItem }) {
    const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
    const children = item.children ?? [];
    const containsCurrent =
        (!item.external && isCurrentOrParentUrl(item.href)) ||
        children.some((child) => isCurrentOrParentUrl(child.href));
    const [open, setOpen] = useState<boolean>(
        () => containsCurrent || (readOpen(item) ?? false),
    );

    function onOpenChange(next: boolean) {
        setOpen(next);
        writeOpen(item, next);
    }

    return (
        <Collapsible asChild open={open} onOpenChange={onOpenChange}>
            <SidebarMenuItem>
                <SidebarMenuButton
                    asChild
                    isActive={!item.external && isCurrentOrParentUrl(item.href)}
                    tooltip={{ children: item.title }}
                >
                    <ItemLink item={item} />
                </SidebarMenuButton>
                <CollapsibleTrigger asChild>
                    <SidebarMenuAction
                        aria-label={`${open ? 'Collapse' : 'Expand'} ${item.title}`}
                        className="data-[state=open]:rotate-90"
                    >
                        <ChevronRight />
                    </SidebarMenuAction>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>
                        {children.map((child) => (
                            <SidebarMenuSubItem key={child.title}>
                                <SidebarMenuSubButton
                                    asChild
                                    isActive={isCurrentUrl(child.href)}
                                >
                                    <Link href={child.href} prefetch>
                                        <span>{child.title}</span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

export function NavMain({
    items = [],
    label = 'Platform',
}: {
    items: NavItem[];
    label?: string;
}) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) =>
                    item.children && item.children.length > 0 ? (
                        <NavSection key={item.title} item={item} />
                    ) : (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={
                                    !item.external &&
                                    isCurrentOrParentUrl(item.href)
                                }
                                tooltip={{ children: item.title }}
                            >
                                <ItemLink item={item} />
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    ),
                )}
            </SidebarMenu>
        </SidebarGroup>
    );
}
