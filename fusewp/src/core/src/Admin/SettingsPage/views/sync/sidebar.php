<?php

use FuseWP\Core\Admin\SettingsPage\SyncList;

/** @global $sync_rule_data */

$db_status = fusewpVarObj($sync_rule_data, 'status', '');

?>
<div class="submitbox" id="submitpost">

    <div id="major-publishing-actions">

        <div class="fusewp-sync-status-wrap">
            <label for="fusewp-status-switch"><?php echo  esc_html__('Status:', 'fusewp') ?></label>
            <select id="fusewp-status-switch" name="sync_status">
                <option value="active"<?php selected('active', $db_status) ?>><?php echo  esc_html__('Active', 'fusewp') ?></option>
                <option value="disabled"<?php selected('disabled', $db_status) ?>><?php echo  esc_html__('Disabled', 'fusewp') ?></option>
            </select>
        </div>

        <div id="delete-action">
            <?php if (fusewpVarGET('fusewp_sync_action') == 'edit') : ?>
                <a class="submitdelete deletion fusewp-confirm-delete" href="<?php echo  esc_url(SyncList::delete_url(absint($_GET['id']))); ?>">
                    <?php echo  esc_html__('Delete', 'fusewp') ?>
                </a>
            <?php endif; ?>
        </div>

        <div id="publishing-action">
            <input type="submit" name="fusewp_save_sync_rule" class="button button-primary button-large" value="<?php echo  esc_html__('Save', 'fusewp') ?>">
        </div>
        <div class="clear"></div>
    </div>

</div>