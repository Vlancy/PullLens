import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    external?: boolean;
    children?: NavItem[];
    /**
     * Permission required to see this item, e.g. 'users.manage'. Omit for items every
     * signed-in user may reach. Hiding an item is presentation only — the server
     * enforces the same permission on the route regardless.
     */
    permission?: string;
};
