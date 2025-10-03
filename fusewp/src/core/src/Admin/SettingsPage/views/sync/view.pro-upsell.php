<?php

$lms = ['LearnDash', 'LifterLMS', 'Tutor LMS', 'MasterStudy LMS', 'Sensei LMS'];

$membership_plugins = [
    'WooCommerce Memberships',
    'MemberPress',
    'ProfilePress',
    'Paid Memberships Pro',
    'Restrict Content Pro',
    'FluentCommunity'
];

$pro_features = [
    esc_html__('Custom Fields & Tagging Support') => [
        esc_html__("Upgrade to map custom fields to profile information and assign tags to users for supported email marketing platforms.", 'fusewp')
    ],
    'Ecommerce Integrations'                      => [
        esc_html__("Sync customers in WooCommerce, WooCommerce Subscriptions, Easy Digital Downloads, and WP Travel Engine with your CRM and email marketing software based on their purchased products, the categories and tags they purchased from.", 'fusewp')
    ],
    'Membership & Community'                     => [
        sprintf(
            esc_html__("Sync members in your membership and community plugin with your email marketing software based on their subscribed plans, membership level and membership status. Supports %s.", 'fusewp'),
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
        esc_html__("Sync custom fields added by Advanced Custom Fields (ACF) to the user profile and form submissions and payments from Gravity Forms, WPForms, Ninja Forms, Contact Form 7, Fluent Forms, Formidable Forms, Everest Forms, Forminator, WS Form, SureForms to your email marketing platform and CRM. Also supports AffiliateWP & SliceWP", 'fusewp')
    ],
    'Premium CRM Integrations'                    => [
        esc_html__("Access premium integrations such as Google Sheets, Salesforce, Ortto.", 'fusewp')
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