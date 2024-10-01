<?php
/** @var mixed $sync_rule_data */
$db_destinations = fusewpVarObj($sync_rule_data, 'destinations', [], true);

if ( ! empty($db_destinations)) $db_destinations = json_decode($db_destinations, true);

$is_edit_page = fusewpVarGET('fusewp_sync_action') == 'edit' && isset($_GET['id']);

$source_id = fusewpVarObj($sync_rule_data, 'source', '');

$has_source_saved = $is_edit_page && ! empty($source_id);

$source_id = fusewp_sync_get_real_source_id($source_id);

?>

<div class="inside">
    <div class="fusewp-actions-container">

        <?php if ($has_source_saved) : $source = fusewp_get_registered_sync_sources($source_id);
            if (is_object($source) && method_exists($source, 'get_rule_information')) {
                printf('<div class="fusewp-sync-source-info">%s</div>', $source->get_rule_information());
            }
        endif; ?>

        <div class="fusewp-no-actions-message"<?php echo  $is_edit_page && ! empty($db_destinations) ? ' style="display:none"' : ''; ?>>
            <p>
                <?php esc_html_e('No source has been selected. Add one before you can set up sync destination.', 'fusewp') ?>
            </p>
        </div>
        <?php if (is_array($db_destinations) && ! empty($db_destinations)) : $index = 1;
            foreach ($db_destinations as $destination) :
                echo fusewp_render_view('action', [
                    'index'               => $index,
                    'source_item_id'      => $source_id,
                    'sync_integration_id' => fusewpVar($destination, 'integration', ''),
                    'db_destinations'     => $db_destinations
                ]);
                $index++;
            endforeach;
        endif;
        ?>
    </div>

    <div class="fusewp-metabox-footer<?php echo  $has_source_saved ? ' fusewp-show' : ''; ?>">
        <a href="#" class="fusewp-add-action button button-primary button-large">
            <?php esc_html_e('+ Add Destination', 'fusewp') ?>
        </a>
    </div>
</div>