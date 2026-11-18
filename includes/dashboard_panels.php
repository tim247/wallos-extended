<?php
// Dashboard panels that can be switched off (Dashboard -> Customise), key => translation key.

const DASHBOARD_PANELS = [
    'upcoming' => 'upcoming_events',
    'tasks' => 'task_inbox',
    'first_steps' => 'first_steps',
    'hints' => 'household_hints',
    'price_increases' => 'price_increases',
    'cancellations' => 'upcoming_cancellations',
    'burden_split' => 'monthly_burden_split',
    'financing' => 'financing_progress',
    'consumption_month' => 'dashboard_panel_consumption_month',
    'meters' => 'dashboard_panel_meter_charts',
    'vehicles' => 'vehicles',
    'children' => 'children',
    'ai' => 'ai_recommendations',
    'budget_month' => 'monthly_budget',
    'budget_period' => 'payment_period',
    'savings' => 'your_savings',
];

/** Switched-off panel keys of the settings row. */
function dashboardHiddenPanels(array $settings): array
{
    return array_values(array_intersect(array_keys(DASHBOARD_PANELS), explode(',', (string) ($settings['dashboard_hidden'] ?? ''))));
}
