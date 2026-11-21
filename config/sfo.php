<?php

return [
    'chat_acknowledgment' => 'Thank you. A licensed SFO agent will follow up by phone, email, or text. This message does not determine eligibility, and product availability varies by state.',

    'permissions' => [
        ['slug' => 'dashboard.view', 'label' => 'Dashboard', 'group' => 'General'],
        ['slug' => 'leads.contact', 'label' => 'Contact leads', 'group' => 'Leads'],
        ['slug' => 'leads.quote', 'label' => 'Free quote leads', 'group' => 'Leads'],
        ['slug' => 'leads.rate', 'label' => 'Instant rate leads', 'group' => 'Leads'],
        ['slug' => 'leads.schedule', 'label' => 'Schedule leads', 'group' => 'Leads'],
        ['slug' => 'leads.chat', 'label' => 'Chat leads', 'group' => 'Leads'],
        ['slug' => 'leads.scope.life', 'label' => 'Limit lead access to Life Insurance', 'group' => 'Leads'],
        ['slug' => 'leads.scope.pnc', 'label' => 'Limit lead access to Property & Casualty', 'group' => 'Leads'],
        ['slug' => 'directory.agent', 'label' => 'Licensed agent directory', 'group' => 'Directory'],
        ['slug' => 'directory.pnc', 'label' => 'P&C directory', 'group' => 'Directory'],
        ['slug' => 'directory.review', 'label' => 'Approve or reject directory applications', 'group' => 'Directory'],
        ['slug' => 'chat.view', 'label' => 'Chat dashboard', 'group' => 'Chat'],
        ['slug' => 'seo.manage', 'label' => 'SEO, AEO, and GEO fields', 'group' => 'Content'],
        ['slug' => 'company.manage', 'label' => 'Manage Company content', 'group' => 'Content'],
        ['slug' => 'careers.manage', 'label' => 'Manage Careers and review applications', 'group' => 'Content'],
        ['slug' => 'explorer.manage', 'label' => 'Manage Site Explorer', 'group' => 'Content'],
        ['slug' => 'users.manage', 'label' => 'Sub-accounts and access', 'group' => 'Admin'],
    ],
];
