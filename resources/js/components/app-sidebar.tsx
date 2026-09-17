import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BarChart2,
    BookOpen,
    Bot,
    Gauge,
    GitPullRequest,
    LayoutGrid,
    Settings,
    ShieldAlert,
    Users,
    ListChecks,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as usersIndex } from '@/routes/admin/users';
import { edit as editAiProviders } from '@/routes/ai-providers';
import { edit as editIntegrations } from '@/routes/integrations';
import { index as repositoriesIndex } from '@/routes/repositories';
import type { Auth, NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Repositories',
        href: repositoriesIndex().url,
        icon: GitPullRequest,
    },
    {
        title: 'Tasks',
        href: '/tasks',
        icon: ListChecks,
        permission: 'tasks.view',
    },
    {
        title: 'Findings',
        href: '/findings',
        icon: ShieldAlert,
        permission: 'findings.view',
    },
    {
        title: 'Users',
        href: usersIndex().url,
        icon: Users,
        permission: 'users.manage',
        children: [
            {
                title: 'Accounts',
                href: usersIndex().url,
                permission: 'users.manage',
            },
            {
                title: 'Roles & Permissions',
                href: rolesIndex().url,
                permission: 'users.manage',
            },
        ],
    },
    {
        title: 'Reports',
        href: '/reports',
        icon: BarChart2,
        permission: 'reports.view',
    },
    {
        title: 'Assistant',
        href: '/assistant',
        icon: Bot,
        permission: 'assistant.use',
    },
    {
        title: 'Settings',
        href: editIntegrations(),
        icon: Settings,
        children: [
            {
                title: 'Git Providers',
                href: editIntegrations(),
                permission: 'integrations.manage',
            },
            {
                title: 'AI Providers',
                href: editAiProviders(),
                permission: 'ai-providers.manage',
            },
        ],
    },
];

/**
 * Drop navigation items the user has no permission for, recursing into children.
 *
 * A parent whose children are all filtered away is dropped too, so the sidebar never
 * shows a section that leads nowhere.
 */
function visibleNavItems(
    items: NavItem[],
    permissions: Record<string, boolean>,
): NavItem[] {
    return items.reduce<NavItem[]>((visible, item) => {
        if (item.permission && !permissions[item.permission]) {
            return visible;
        }

        if (!item.children) {
            return [...visible, item];
        }

        const children = visibleNavItems(item.children, permissions);

        return children.length > 0
            ? [...visible, { ...item, children }]
            : visible;
    }, []);
}

/**
 * The user guide, served by the application itself at /docs. It opens in a new tab
 * because it is plain HTML outside the SPA, so an Inertia visit would not load it.
 */
const docsNavItem: NavItem = {
    title: 'Documentation',
    href: '/docs',
    icon: BookOpen,
    external: true,
};

export function AppSidebar() {
    const { telescope_enabled, horizon_enabled, docs_enabled, auth } = usePage<{
        telescope_enabled: boolean;
        horizon_enabled: boolean;
        docs_enabled: boolean;
        auth: Auth;
    }>().props;

    const navItems = visibleNavItems(mainNavItems, auth?.permissions ?? {});

    const monitorNavItems: NavItem[] = [
        ...(telescope_enabled
            ? [
                  {
                      title: 'Telescope',
                      href: '/telescope',
                      icon: Activity,
                      external: true,
                  },
              ]
            : []),
        ...(horizon_enabled
            ? [
                  {
                      title: 'Horizon',
                      href: '/horizon',
                      icon: Gauge,
                      external: true,
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
                {monitorNavItems.length > 0 && (
                    <NavMain items={monitorNavItems} label="Monitor" />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter
                    items={docs_enabled ? [docsNavItem] : []}
                    className="mt-auto"
                />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
