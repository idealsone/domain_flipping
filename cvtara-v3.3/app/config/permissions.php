<?php

/*
 * Staff roles and permissions (3.37.0).
 *
 * The owner (users.is_admin = true) is the Super Admin and can do everything. Staff accounts get one role from
 * 'roles'. Every /api/admin/... call is checked against 'routes' in the router (ApiController) BEFORE any
 * controller runs: the first matching rule decides which permission is needed. A path that matches no rule is
 * Super Admin only (deny by default), so a new console endpoint is never open to staff by accident.
 *
 * Rule format: [method, regex on the path after "admin/", permission]. method is GET, POST or * (any).
 * Permission "owner" means Super Admin only.
 */

return [
    'permissions' => [
        'overview.view' => 'See the overview numbers',
        'customers.view' => 'Find customers and see their account, plan and orders',
        'customers.manage' => 'Give or end complimentary packages, edit a customer\'s details',
        'orders.view' => 'See orders and payments',
        'orders.refund' => 'Approve or reject refund requests',
        'services.manage' => 'Work the Expert Review / Profile Boost queue',
        'support.view' => 'Read support tickets',
        'support.manage' => 'Answer, assign and close support tickets',
        'customers.view_as' => 'Open a read-only view of a customer\'s account from a support ticket (30 minutes, every page logged)',
        'content.edit' => 'Edit site content, guides, reviews, What\'s new and templates',
        'seo.view' => 'See SEO pages, keywords and the SEO agent',
        'seo.draft' => 'Run SEO scans and record keywords and link prospects',
        'seo.approve' => 'Approve or undo SEO changes and change SEO agent settings',
        'growth.view' => 'See growth, revenue, customers and campaigns',
        'growth.draft' => 'Prepare campaign plans, experiments, outreach lists and email drafts',
        'growth.approve' => 'Send campaigns and outreach, publish offers, change growth agent settings',
        'reports.view' => 'See and download reports',
        'ops.view' => 'See the Error Log, health and speed',
        'ops.manage' => 'Clear errors, rebuild pages, run health checks',
        'referrals.manage' => 'Review referral payouts and creator applications',
    ],

    'roles' => [
        'admin' => ['name' => 'Admin', 'about' => 'Day-to-day operations. No keys, prices, deletes, roles or business assistant.',
            'can' => ['overview.view', 'customers.view', 'customers.manage', 'customers.view_as', 'orders.view', 'orders.refund', 'services.manage', 'support.view', 'support.manage', 'content.edit', 'seo.view', 'seo.draft', 'seo.approve', 'growth.view', 'growth.draft', 'growth.approve', 'reports.view', 'ops.view', 'ops.manage', 'referrals.manage']],
        'support_manager' => ['name' => 'Support Manager', 'about' => 'Runs support: all tickets, customer lookups, orders, Expert Review queue.',
            'can' => ['overview.view', 'customers.view', 'customers.view_as', 'orders.view', 'services.manage', 'support.view', 'support.manage', 'ops.view']],
        'support_agent' => ['name' => 'Support Agent', 'about' => 'Answers support tickets and looks up customers.',
            'can' => ['customers.view', 'support.view', 'support.manage']],
        'seo_manager' => ['name' => 'SEO Manager', 'about' => 'SEO research, scans and drafts. Changes still need an Admin or the owner to approve.',
            'can' => ['seo.view', 'seo.draft', 'reports.view']],
        'growth_manager' => ['name' => 'Growth Manager', 'about' => 'Campaign and experiment drafts and growth numbers. Sends need approval.',
            'can' => ['overview.view', 'growth.view', 'growth.draft', 'reports.view']],
        'content_editor' => ['name' => 'Content Editor', 'about' => 'Site content, guides, reviews and What\'s new.',
            'can' => ['content.edit', 'seo.view']],
        'finance_manager' => ['name' => 'Finance Manager', 'about' => 'Orders, refunds, referral payouts and financial reports.',
            'can' => ['overview.view', 'orders.view', 'orders.refund', 'reports.view', 'referrals.manage']],
    ],

    // Console modules (resources/console/NAME.js) a role may load; a module not listed is owner only.
    'modules' => [
        'ops' => 'overview.view', 'people' => 'customers.view', 'reports' => 'reports.view', 'feedback' => 'support.view',
        'content' => 'content.edit', 'publish' => 'content.edit', 'whatsnew' => 'content.edit', 'extras' => 'content.edit',
        'growth' => 'growth.view|seo.view', 'agents' => 'growth.view|seo.view', 'operators' => 'growth.view',
        'campaigns' => 'growth.view', 'outreach' => 'growth.view', 'promos' => 'growth.view',
        'refunds' => 'orders.view', 'referral' => 'referrals.manage', 'speed' => 'ops.view', 'team' => 'owner', 'command' => 'staff', 'approvals' => 'staff', 'metrics' => 'overview.view',
    ],

    'routes' => [
        // Signed-in staff can always read who they are and what they may do.
        ['GET', '#^me$#', 'staff'],
        // The public site settings (hero text, accent colour) every console page reads on load: no secrets.
        ['GET', '#^settings$#', 'staff'],
        // Plan names and prices (public on the Packages page) and the on/off service switches, read by several views.
        ['GET', '#^(plans|content/integrations)$#', 'staff'],
        ['GET', '#^content/(seo|blog|library|features|legal|site)$#', 'content.edit|seo.view'],
        ['GET', '#^growth/agents$#', 'growth.view|seo.view'],  // agent on/off status only
        ['GET', '#^dashboard$#', 'overview.view'],
        // Ctrl+K search: every console role may search; the answer holds only the kinds the role can see.
        ['GET', '#^find$#', 'staff'],
        // 3.43.0 executive numbers and payment reconciliation (running it and resolving are owner only: no rule).
        ['GET', '#^metrics$#', 'overview.view'],
        ['GET', '#^reconciliation$#', 'orders.view'],
        // 3.42.0 Approval Centre and tasks: every item is checked again against its own permission inside.
        ['GET', '#^approvals$#', 'staff'],
        ['POST', '#^approvals/decide$#', 'staff'],
        ['GET', '#^tasks(/[a-f0-9-]{36})?$#', 'seo.view|growth.view'],
        ['POST', '#^tasks(/[a-f0-9-]{36}/(move|evidence))?$#', 'seo.draft|growth.draft|seo.approve|growth.approve'],

        // Owner only: keys, prices, business switches, setup, roles, the business assistant, deletes and blocks.
        ['*', '#^(settings|providers|plans|business-controls|setup/.*|team(/.*)?|tara(/.*)?|users/(block|delete)|refunds/settings|services/settings|referrals/settings|campaigns/settings|outreach/(settings|senders)|speed/(save|test-reader)|report/fresh-start|media|indexing/settings|growth/(settings|costs|levels)|errors/clear|audit(/.*)?)$#', 'owner'],

        ['GET', '#^(overview|live|letter-summary)$#', 'overview.view'],
        ['GET', '#^(users|users/grants|purchases)$#', 'customers.view'],
        ['POST', '#^users/(grant|grant-revoke|update|revoke)$#', 'customers.manage'],
        ['GET', '#^orders/[0-9a-f-]{36}$#', 'orders.view'],
        ['GET', '#^refunds$#', 'orders.view'],
        ['POST', '#^refunds/[0-9a-f-]{36}/(approve|reject)$#', 'orders.refund'],
        ['GET', '#^services(/[0-9a-f-]{36})?$#', 'services.manage'],
        ['POST', '#^services/[0-9a-f-]{36}/(review|resend|retry)$#', 'services.manage'],

        ['GET', '#^support/team$#', 'support.manage'],
        ['POST', '#^support/new$#', 'support.manage'],
        ['GET', '#^support/users/\d+$#', 'customers.view'],
        ['POST', '#^support/view/sessions/[a-f0-9-]{36}/end$#', 'owner'],
        ['*', '#^support/view/(start|end|sessions)$#', 'customers.view_as'],
        ['GET', '#^support(/summary|/[a-f0-9-]{36}|/file/[a-f0-9-]{36})?$#', 'support.view'],
        ['POST', '#^support/[a-f0-9-]{36}$#', 'support.manage'],

        ['GET', '#^(report|report/meta|report/kpis|reports)$#', 'reports.view'],

        ['*', '#^(content/(legal|features|library|blog|emails|popup|site)|reviews|feedback/add|whats-new(/save)?|legal/generate|email/preview)$#', 'content.edit'],

        ['GET', '#^(seo|page-audit|site-urls|search/rows|indexing|growth/seo(/(proposals|tracked|overview|pages|page|keywords|audit-lists|versions))?|growth/links|growth/visibility(/tests)?|growth/agents/log)$#', 'seo.view'],
        ['POST', '#^(growth/seo/(scan|inspect|keyword|audit|web-vitals|draft|versions/\d+/verify|page/(record|audit|reviewed))|growth/link(/verify|/delete)?|growth/links/draft|growth/visibility/(test|prompts|audit))$#', 'seo.draft'],
        ['POST', '#^(growth/seo/(decide|run|settings)|growth/visibility/policy|indexing/(submit|sitemap)|search/(import|sync)|content/seo)$#', 'seo.approve'],

        ['GET', '#^(growth|growth/reports|growth/report|growth/revenue|growth/campaign-plans|growth/experiments|growth/customers|growth/checkout|growth/customer|growth/scorecard|growth/agents|campaigns|campaigns/(count|contacts|preview)|outreach|outreach/(contacts|export|preview)|promos|promos/employers)$#', 'growth.view'],
        ['POST', '#^(growth/(diagnose|campaign-plan|experiment|events/sync|report-now)|campaigns/(save|test|preview|count)|outreach/(import|preview)|promos/save)$#', 'growth.draft'],
        ['POST', '#^(growth/agents|campaigns/(send|contacts/import|contacts/delete|suppress|pause|resume|cancel|delete)|outreach/(suppress|stop-queued)|promos/(coupon|live|schedule|off|delete))$#', 'growth.approve'],

        ['GET', '#^(errors|errors/download|health/history|speed/status|email/jobs)$#', 'ops.view'],
        ['POST', '#^(health/run|speed/(rebuild|refresh|purge)|email/process)$#', 'ops.manage'],

        ['*', '#^referrals(/.*)?$#', 'referrals.manage'],
    ],
];
