import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    BarChart2,
    Bot,
    Gauge,
    GitPullRequest,
    LayoutGrid,
    Settings,
    Users,
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
import { edit as editAiProviders } from '@/routes/ai-providers';
import { edit as editIntegrations } from '@/routes/integrations';
import { index as repositoriesIndex } from '@/routes/repositories';
import { index as usersIndex } from '@/routes/admin/users';
import type { NavItem } from '@/types';

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
        title: 'Users',
        href: usersIndex().url,
        icon: Users,
    },
    {
        title: 'Reports',
        href: '/reports',
        icon: BarChart2,
    },
    {
        title: 'Assistant',
        href: '/assistant',
        icon: Bot,
    },
    {
        title: 'Settings',
        href: editIntegrations(),
        icon: Settings,
        children: [
            {
                title: 'Git Providers',
                href: editIntegrations(),
            },
            {
                title: 'AI Providers',
                href: editAiProviders(),
            },
        ],
    },
];

const footerNavItems: NavItem[] = [
    // {
    //     title: 'Repository',
    //     href: 'https://github.com/Vlancy/PullLens',
    //     icon: FolderGit2,
    // },
];

export function AppSidebar() {
    const { telescope_enabled, horizon_enabled } = usePage().props;

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
                <NavMain items={mainNavItems} />
                {monitorNavItems.length > 0 && (
                    <NavMain items={monitorNavItems} label="Monitor" />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
