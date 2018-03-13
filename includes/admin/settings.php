<?php defined('ABSPATH') or die('No direct script access!'); ?>

<form id="p18aw-settings" name="p18aw-settings" method="post" action="<?php echo admin_url('admin.php?page=' . P18AW_PLUGIN_ADMIN_URL); ?>">
    <?php wp_nonce_field('save-settings', 'p18aw-nonce'); ?>
</form>

<div class="wrap">

    <?php include P18AW_ADMIN_DIR . 'header.php'; ?>

    <div class="p18a-page-wrapper">

        <br>
        <table class="p18a">

            <tr>
                <td class="p18a-label">
                    <label for="p18a-walkin_number"><?php _e('Walk in customer number', 'p18a'); ?></label>
                </td>
                <td>
                    <input id="p18aw-walkin_number" type="text" name="walkin_number" form="p18aw-settings" value="<?php echo $this->option('walkin_number'); ?>">
                </td>
            </tr>


            <tr>
                <td colspan="2">
                    <h2><?php _e('Shipping methods', 'p18a'); ?></h2>
                </td>
            </tr>

            <?php


            $active_methods = [];

            $zones = WC_Shipping_Zones::get_zones();

            foreach($zones as $zone) {

                $worldwide = new \WC_Shipping_Zone($zone['id']);
                $methods   = $worldwide->get_shipping_methods();
    
                foreach ($methods as $method) {
                    if ($method->enabled === 'yes') {
                        $active_methods[$method->instance_id] = [
                            'id'    => $method->id,
                            'title' => $method->title,
                            'zone'  => $zone['zone_name']
                        ];
                    }
                }

            }

            ?>

            <?php foreach($active_methods as $instance => $data): ?>

            <tr>
                <td class="p18a-label">
                    <label for="p18a-shipping_<?php echo $data['id']; ?>"><?php echo $data['zone']; ?> [<?php echo $data['title']; ?>]</label>
                </td>
                <td>
                    <input id="p18a-shipping_<?php echo $data['id']; ?>" type="text" name="shipping[<?php echo $data['id'] . '_' . $instance; ?>]" value="<?php echo $this->option('shipping_' . $data['id'] . '_' . $instance); ?>" form="p18aw-settings">                   
                </td>
            </tr>
                        
            <?php endforeach; ?>



            <tr>
                <td colspan="2">
                    <h2><?php _e('Payment methods', 'p18a'); ?></h2>
                </td>
            </tr>

            <?php


            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            $enabled_gateways = [];

            foreach($gateways as $gateway) {
                if($gateway->enabled == 'yes') {
                    $enabled_gateways[$gateway->id] = $gateway->title;
                }
            }

            ?>

            <?php foreach($enabled_gateways as $id => $title): ?>

            <tr>
                <td class="p18a-label">
                    <label for="p18a-payment_<?php echo $id; ?>"><?php echo $title; ?></label>
                </td>
                <td>
                    <input id="p18a-payment_<?php echo $id; ?>" type="text" name="payment[<?php echo $id; ?>]" value="<?php echo $this->option('payment_' . $id); ?>" form="p18aw-settings">                   
                </td>
            </tr>
                        
            <?php endforeach; ?>

        </table>

        <br>

        <input type="submit" class="button-primary" value="<?php _e('Save changes', 'p18a'); ?>" name="p18aw-save-settings" form="p18aw-settings" />

    </div>
</div>