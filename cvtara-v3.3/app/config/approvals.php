<?php

/*
 * Approval Centre (3.42.0): every kind of change that waits for a person, the permission needed to decide it and whether
 * a fresh sign-in check (authenticator code / password) is asked first. The owner can decide everything. Staff see only
 * the kinds their role may decide. Nobody can approve something they asked for themselves.
 */
return [
    'categories' => [
        'seo' => ['label' => 'SEO change', 'permission' => 'seo.approve', 'stepUp' => false],
        'refund' => ['label' => 'Refund', 'permission' => 'orders.refund', 'stepUp' => true],
        'tara' => ['label' => 'Business assistant action', 'permission' => 'owner', 'stepUp' => true],
        'task_seo' => ['label' => 'SEO task', 'permission' => 'seo.approve', 'stepUp' => false],
        'task_growth' => ['label' => 'Growth task', 'permission' => 'growth.approve', 'stepUp' => false],
        'task_staff' => ['label' => 'Team task', 'permission' => 'owner', 'stepUp' => false],
    ],
    // Tasks still open this many days after their due date are marked Overdue by the cron; approvals older than this
    // many hours show as waiting too long.
    'waitingHours' => 48,
];
