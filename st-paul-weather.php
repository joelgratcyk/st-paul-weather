<?php
/**
 * Plugin Name: St Paul Weather Forecast
 * Description: Displays current NWS weather + 24-36hr forecast for St. Paul, MN via shortcode.
 * Version:     1.0.0
 * Author:      Fried Egg Burger
 * Author URI:  https://friedeggburger.com
 * License:     GPL2+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function spwf_get_weather_data() {
    $transient_key = 'spwf_weather_mpx';
    $cached = get_transient( $transient_key );
    if ( false !== $cached ) return $cached;

    // Twin Cities NWS office gridpoint for St. Paul
    $grid_url = 'https://api.weather.gov/gridpoints/MPX/94,93/forecast';
    
    $response = wp_remote_get( $grid_url, array(
        'timeout' => 10,
        'headers' => array( 'User-Agent' => 'FriedEggBurgerWP/1.0' )
    ));
    
    if ( is_wp_error( $response ) ) {
        return array( 'error' => 'Weather service unavailable.' );
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $code ) {
        return array( 'error' => 'Weather service error: ' . $code );
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( empty( $data['properties']['periods'] ) ) {
        return array( 'error' => 'No forecast data available.' );
    }

    $periods = $data['properties']['periods'];
    $current = $periods[0]; // Now
    $next_3 = array_slice( $periods, 0, 4 ); // Now + next 3 periods (~36hrs)

    $result = array(
        'current' => array(
            'temp' => isset( $current['temperature'] ) ? $current['temperature'] : null,
            'unit' => isset( $current['temperatureUnit'] ) ? $current['temperatureUnit'] : 'F',
            'summary' => $current['shortForecast'] ?? $current['name'],
            'detailed' => $current['detailedForecast'] ?? '',
            'wind' => $current['windSpeed'] ?? '',
            'icon' => $current['icon'] ?? ''
        ),
        'forecast' => array(),
        'updated' => current_time( 'c' )
    );

    // Next 3 periods
    foreach ( array_slice( $periods, 1, 3 ) as $period ) {
        $result['forecast'][] = array(
            'start' => $period['startPeriodName'],
            'temp' => $period['temperature'] ?? null,
            'summary' => $period['shortForecast'],
            'icon' => $period['icon']
        );
    }

    set_transient( $transient_key, $result, 20 * MINUTE_IN_SECONDS );
    return $result;
}

function spwf_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'title' => 'St. Paul Weather' ), $atts, 'st_paul_weather' );
    
    $weather = spwf_get_weather_data();
    if ( isset( $weather['error'] ) ) {
        return '<div class="spwf-weather spwf-error">' . esc_html( $weather['error'] ) . '</div>';
    }

    $current = $weather['current'];
    ob_start(); ?>
    <div class="spwf-weather">
        <h3 class="spwf-title"><?php echo esc_html( $atts['title'] ); ?></h3>
        
        <div class="spwf-current">
            <div class="spwf-temp">
                <?php if ( $current['temp'] ) : ?>
                    <span class="spwf-temp-value"><?php echo esc_html( $current['temp'] ); ?>°</span>
                    <span class="spwf-temp-unit"><?php echo $current['unit']; ?></span>
                <?php endif; ?>
            </div>
            <div class="spwf-summary"><?php echo esc_html( $current['summary'] ); ?></div>
            <?php if ( $current['wind'] ) : ?>
                <div class="spwf-wind"><?php echo esc_html( $current['wind'] ); ?></div>
            <?php endif; ?>
        </div>

        <div class="spwf-forecast">
            <h4>Next 36 Hours</h4>
            <div class="spwf-forecast-grid">
                <?php foreach ( $weather['forecast'] as $fc ) : ?>
                    <div class="spwf-forecast-item">
                        <div class="spwf-period"><?php echo esc_html( $fc['start'] ); ?></div>
                        <?php if ( $fc['temp'] ) : ?>
                            <div class="spwf-fc-temp"><?php echo esc_html( $fc['temp'] ); ?>°</div>
                        <?php endif; ?>
                        <div class="spwf-fc-summary"><?php echo esc_html( $fc['summary'] ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <p class="spwf-source">
            National Weather Service • Updated <?php echo date( 'g:i A', strtotime( $weather['updated'] ) ); ?>
        </p>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'st_paul_weather', 'spwf_shortcode' );

function spwf_enqueue_styles() {
    if ( ! is_admin() ) {
        wp_register_style( 'spwf-styles', false );
        wp_enqueue_style( 'spwf-styles' );
        wp_add_inline_style( 'spwf-styles', "
            .spwf-weather{background:#e3f2fd;border:1px solid #bbdefb;border-radius:12px;padding:1.5rem;margin:1rem 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
            .spwf-title{margin:0 0 1rem;color:#1565c0;font-size:1.3rem;font-weight:600;}
            .spwf-current{display:flex;align-items:center;gap:1rem;margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid #bbdefb;}
            .spwf-temp-value{font-size:2.5rem;font-weight:700;color:#0277bd;line-height:1;}
            .spwf-temp-unit{font-size:1.2rem;color:#0277bd;}
            .spwf-summary{font-size:1.1rem;font-weight:500;color:#1565c0;}
            .spwf-wind{font-size:0.9rem;color:#455a64;}
            .spwf-forecast h4{margin:0 0 0.75rem 0;color:#0277bd;font-size:1rem;}
            .spwf-forecast-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:0.75rem;}
            .spwf-forecast-item{background:#fff;border-radius:8px;padding:0.75rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.1);}
            .spwf-period{font-size:0.85rem;color:#455a64;font-weight:500;margin-bottom:0.25rem;}
            .spwf-fc-temp{font-size:1.3rem;font-weight:600;color:#0277bd;}
            .spwf-fc-summary{font-size:0.8rem;color:#546e7a;}
            .spwf-source{font-size:0.75rem;color:#78909c;margin:0;text-align:center;}
            .spwf-error{background:#ffebee;color:#c62828;border-color:#ef5350;}
        " );
    }
}
add_action( 'wp_enqueue_scripts', 'spwf_enqueue_styles' );
?>
