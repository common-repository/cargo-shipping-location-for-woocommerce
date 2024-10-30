<?php
if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="cargo-map-wrap">
    <?php if ($data['boxStyle'] === 'cargo_map' ) :
        $maps_key = get_option('cslfw_google_api_key');
        ?>
        <?php if ($maps_key) : ?>
            <a class="baldrap-btn btn button wp-element-button" id="mapbutton">
                <?php esc_html_e(' בחירת נקודה', 'cargo-shipping-location-for-woocommerce') ?>
            </a>
            <div class="selected_cargo"></div>
        <?php endif; ?>
    <?php
    elseif ($data['boxStyle'] === 'cargo_dropdowns') :
        if ( $data['cities'] ) { ?>
            <div class="form-row form-row-wide">
                <label for="cargo_city">
                    <span><?php esc_html_e('בחירת עיר', 'cargo-shipping-location-for-woocommerce') ?></span>
                </label>

                <div class="cargo-select-wrap">
                    <select name="cargo_city" id="cargo_city" class="">
                        <option><?php esc_html_e('נא לבחור עיר', 'cargo-shipping-location-for-woocommerce') ?></option>
                        <?php foreach ($data['cities'] as $city) : ?>
                            <option value="<?php echo esc_attr($city) ?>" <?php if (trim($data['selectedCity']) === trim($city) ) echo esc_attr('selected="selected"'); ?>><?php echo esc_html($city) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php }
        if ($data['points']) {
            ?>
            <div class="form-row form-row-wide">
                <label for="cargo_pickup_point">
                    <span><?php esc_html_e('בחירת נקודת חלוקה', 'cargo-shipping-location-for-woocommerce') ?></span>
                </label>
                <div class="cargo-select-wrap">
                    <select name="cargo_pickup_point" id="cargo_pickup_point" class=" w-100">
                    <?php foreach ($data['points'] as $key => $point) :?>
                        <option value="<?php echo esc_attr($point->DistributionPointID) ?>" <?php if ($data['selectedPointId'] === $point->DistributionPointID) echo 'selected="selected"' ?>>
                            <?php echo esc_html($point->DistributionPointName) ?>, <?php echo esc_html($point->CityName) ?>, <?php echo esc_html($point->StreetName) ?> <?php echo esc_html($point->StreetNum) ?>
                        </option>
                    <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php } else { ?>
            <p class="woocommerce-info"><?php esc_html_e('לא נמצאו כתובות ברדיוס של 10 ק״מ נא לבחור עיר אחרת', 'cargo-shipping-location-for-woocommerce') ?></p>
        <?php } ?>
    <?php endif; ?>
    <?php
    if ($data['selectedPoint']) {
        $selectedPoint = $data['selectedPoint'];
    }
    if ($data['boxStyle'] !== 'cargo_automatic') :
        ?>
        <input type="hidden" id="DistributionPointID" name="DistributionPointID" value="<?php echo esc_attr( $selectedPoint->DistributionPointID ?? '' )?>">
        <input type="hidden" id="CityName" name="CityName" value="<?php echo esc_attr( $selectedPoint->CityName ?? '' ) ?>">
    <?php endif; ?>
</div>
