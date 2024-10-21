<?php

$lms = ['LearnDash', 'LifterLMS', 'Tutor LMS'];

$membership_plugins = [
    'WooCommerce Memberships',
    'MemberPress',
    'ProfilePress',
    'Paid Memberships Pro',
    'Restrict Content Pro',
];

$pro_features = [
    esc_html__('Custom Fields & Tagging Support') => [
        esc_html__("Upgrade to map custom fields to profile information and assign tags to users for supported email marketing platforms.", 'fusewp')
    ],
    'Ecommerce Integrations'                      => [
        esc_html__("Sync customers in WooCommerce, WooCommerce Subscriptions and Easy Digital Downloads with your CRM and email marketing software based on their purchased products, the categories and tags they purchased from.", 'fusewp')
    ],
    'Membership Integrations'                     => [
        sprintf(
            esc_html__("Sync members in your membership plugin with your email marketing software based on their subscribed plans, membership level and membership status. Supports %s.", 'fusewp'),
            implode(', ', $membership_plugins)
        )
    ],
    'LMS Integrations'                            => [
        sprintf(
            esc_html__("Sync students in your LMS plugin with your email marketing software based on their enrolled courses, memberships, groups and enrollment status. Supports %s.", 'fusewp'),
            implode(', ', $lms)
        )
    ],
    'Forms & Other Integrations'                  => [
        esc_html__("Sync custom fields added by Advanced Custom Fields (ACF) to the user profile and form submissions and payments from Gravity Forms, WPForms to your email marketing platform and CRM.", 'fusewp')
    ]
];
?>

<div class="fusewp-pro-features-wrap">
    <?php foreach ($pro_features as $label => $feature): ?>
        <div class="fusewp-pro-features">
            <strong><?php echo esc_html($label) ?>:</strong> <?php echo esc_html(implode(', ', $feature)) ?>
        </div>
    <?php endforeach; ?>
    <div>
        <a href="https://fusewp.com/pricing/?utm_source=wp_dashboard&utm_medium=upgrade&utm_campaign=sync_pro_upgrade_metabox" target="__blank" class="button-primary">
            <?php esc_html_e('Get FuseWP Pro →', 'fusewp') ?>
        </a>
    </div>
</div>