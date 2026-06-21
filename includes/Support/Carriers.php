<?php
/**
 * Carrier definitions and tracking URL helpers.
 *
 * @package Kitgenix\OrderTracking
 */

declare(strict_types=1);

namespace Kitgenix\OrderTracking\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Carrier registry and helper methods.
 */
final class Carriers {

    private const OPTION_KEY = 'kitgenix_order_tracking_for_woocommerce_carriers_settings';

    /**
     * Get recommended carrier pack definitions.
     *
     * @return array<string,array{label:string,description:string,carriers:array<int,string>}>
     */
    public static function pack_options(): array {
        $global_carriers = array_values(
            array_filter(
                array_keys( self::all() ),
                static fn( string $key ): bool => $key !== 'other'
            )
        );

        return [
            'uk_ie' => [
                'label'       => __( 'UK & Ireland', 'kitgenix-order-tracking-for-woocommerce' ),
                'description' => __( 'Keeps Royal Mail, DPD, An Post, Parcelforce, Evri, DX, UPS UK/Ireland, Woodside, and similar local carriers at the top for UK and Irish fulfilment teams.', 'kitgenix-order-tracking-for-woocommerce' ),
                'carriers'    => [ 'amazon_logistics', 'an_post', 'dpd_ie', 'dpd_uk', 'dx', 'evri', 'parcel_force', 'royal_mail', 'ups_ie', 'ups_uk', 'woodside', 'yodel', 'dhl', 'fedex' ],
            ],
            'europe' => [
                'label'       => __( 'Europe', 'kitgenix-order-tracking-for-woocommerce' ),
                'description' => __( 'Prioritises broader European carrier coverage such as Colissimo, Chronopost, Correos, Deutsche Post DHL, GLS, PostNL, Bpost, and PostNord alongside DHL and FedEx.', 'kitgenix-order-tracking-for-woocommerce' ),
                'carriers'    => [ 'dhl', 'fedex', 'bpost', 'chronopost', 'colissimo', 'correos', 'deutsche_post_dhl', 'gls', 'postnl', 'postnord' ],
            ],
            'north_america' => [
                'label'       => __( 'North America', 'kitgenix-order-tracking-for-woocommerce' ),
                'description' => __( 'Surfaces USPS, UPS US, Canada Post, DHL, and FedEx for stores shipping mainly in the United States and Canada.', 'kitgenix-order-tracking-for-woocommerce' ),
                'carriers'    => [ 'usps', 'ups_us', 'canada_post', 'dhl', 'fedex' ],
            ],
            'oceania' => [
                'label'       => __( 'Australia & New Zealand', 'kitgenix-order-tracking-for-woocommerce' ),
                'description' => __( 'Highlights Australia Post, NZ Post, DHL, and FedEx for stores shipping in Australia, New Zealand, and nearby routes.', 'kitgenix-order-tracking-for-woocommerce' ),
                'carriers'    => [ 'australia_post', 'nz_post', 'dhl', 'fedex' ],
            ],
            'global' => [
                'label'       => __( 'Global / mixed carriers', 'kitgenix-order-tracking-for-woocommerce' ),
                'description' => __( 'Enables the full built-in carrier library for stores with multiple shipping regions or mixed operational workflows.', 'kitgenix-order-tracking-for-woocommerce' ),
                'carriers'    => $global_carriers,
            ],
        ];
    }

    /**
     * Get the recommended carrier pack for the current store country.
     */
    public static function recommended_pack(): string {
        return self::recommended_pack_for_country( self::get_store_country() );
    }

    /**
     * Determine a recommended pack from a country code.
     */
    public static function recommended_pack_for_country( string $country_code ): string {
        $country_code = strtoupper( trim( $country_code ) );

        if ( in_array( $country_code, [ 'GB', 'IE' ], true ) ) {
            return 'uk_ie';
        }

        if ( in_array( $country_code, [ 'US', 'CA' ], true ) ) {
            return 'north_america';
        }

        if ( in_array( $country_code, [ 'AU', 'NZ' ], true ) ) {
            return 'oceania';
        }

        if ( in_array( $country_code, [ 'AT', 'BE', 'CH', 'CZ', 'DE', 'DK', 'ES', 'FI', 'FR', 'IT', 'LU', 'NL', 'NO', 'PL', 'PT', 'SE' ], true ) ) {
            return 'europe';
        }

        return 'global';
    }

    /**
     * Apply a recommended carrier pack to built-in carrier settings.
     *
     * @param string $pack_key Carrier pack key.
     * @param array{custom?:array<int,array{key:string,label:string,url_pattern:string,icon:string,enabled:bool,priority:int}>} $settings Existing settings to preserve custom carriers.
     *
     * @return array{builtin_disabled:array<int,string>,builtin_priority:array<string,int>,custom:array<int,array{key:string,label:string,url_pattern:string,icon:string,enabled:bool,priority:int}>,carrier_pack:string}
     */
    public static function apply_pack_settings( string $pack_key, array $settings = [] ): array {
        $packs = self::pack_options();
        if ( ! isset( $packs[ $pack_key ] ) ) {
            $pack_key = self::recommended_pack();
        }

        $builtin = self::builtin();
        $pack    = $packs[ $pack_key ] ?? $packs['global'];
        $allowed = array_fill_keys( $pack['carriers'], true );

        $builtin_disabled = [];
        $builtin_priority = [];
        $priority         = 1;

        foreach ( $pack['carriers'] as $carrier_key ) {
            if ( $carrier_key === 'other' || ! isset( $builtin[ $carrier_key ] ) ) {
                continue;
            }

            $builtin_priority[ $carrier_key ] = $priority;
            $priority++;
        }

        foreach ( array_keys( $builtin ) as $carrier_key ) {
            if ( $carrier_key === 'other' ) {
                continue;
            }

            if ( ! isset( $allowed[ $carrier_key ] ) ) {
                $builtin_disabled[] = $carrier_key;
            }
        }

        return [
            'builtin_disabled' => array_values( array_unique( $builtin_disabled ) ),
            'builtin_priority' => $builtin_priority,
            'custom'           => isset( $settings['custom'] ) && is_array( $settings['custom'] ) ? $settings['custom'] : [],
            'carrier_pack'     => $pack_key,
        ];
    }

    /**
     * Get the WooCommerce base country code if available.
     */
    private static function get_store_country(): string {
        if ( function_exists( 'wc_get_base_location' ) ) {
            $base_location = wc_get_base_location();
            if ( isset( $base_location['country'] ) ) {
                return strtoupper( (string) $base_location['country'] );
            }
        }

        return '';
    }

    /**
     * Get all supported carriers.
     *
     * @return array<string,array<string,string>> Array keyed by carrier key.
     */
    public static function all(): array {
        return [
            'amazon_logistics' => [
                'label'       => \__( 'Amazon Logistics', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://track.amazon.co.uk/tracking/{tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'an_post'     => [
                'label'       => \__( 'An Post', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.anpost.com/Post-Parcels/Track/Search?item={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/an-post.svg',
            ],
            'dhl'         => [
                'label'       => \__( 'DHL', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.dhl.com/gb-en/home/tracking.html?tracking-id={tracking_number}&submit=1',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/dhl.svg',
            ],
            'dpd_ie'      => [
                'label'       => \__( 'DPD Ireland', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.dpd.ie/tracking/?consignmentNumber={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/dpd-ie.svg',
            ],
            'dpd_uk'      => [
                'label'       => \__( 'DPD UK', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://track.dpd.co.uk/search?reference={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/dpd-uk.svg',
            ],
            'dx'          => [
                'label'       => \__( 'DX', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://my.dxdelivery.com/t/{tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'evri'        => [
                'label'       => \__( 'Evri', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.evri.com/track/parcel/{tracking_number}/details',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'fedex'       => [
                'label'       => \__( 'FedEx', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.fedex.com/fedextrack/?trknbr={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'bpost'       => [
                'label'       => \__( 'Bpost', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://track.bpost.cloud/track/items?itemIdentifier={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'canada_post' => [
                'label'       => \__( 'Canada Post', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/details/{tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'chronopost'  => [
                'label'       => \__( 'Chronopost', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'colissimo'   => [
                'label'       => \__( 'Colissimo / La Poste', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.laposte.fr/outils/suivre-vos-envois?code={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'correos'     => [
                'label'       => \__( 'Correos', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.correos.es/es/en/tools/track/status?tracking-number={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'deutsche_post_dhl' => [
                'label'       => \__( 'Deutsche Post / DHL Germany', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.dhl.de/en/privatkunden/dhl-sendungsverfolgung.html?piececode={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/dhl.svg',
            ],
            'woodside'    => [
                'label'       => \__( 'Woodside Distribution', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.woodsides.com/contact/',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/woodside.svg',
            ],
            'gls'         => [
                'label'       => \__( 'GLS', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://gls-group.com/GROUP/en/parcel-tracking/?match={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'nz_post'     => [
                'label'       => \__( 'NZ Post', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.nzpost.co.nz/tools/tracking/item/{tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'other'       => [
                'label'       => \__( 'Other carrier', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => '',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'parcel_force' => [
                'label'       => \__( 'Parcelforce Worldwide', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.royalmail.com/track-your-item#/tracking-results/{tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/parcelforce.svg',
            ],
            'royal_mail'  => [
                'label'       => \__( 'Royal Mail', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.royalmail.com/track-your-item#/tracking-results/{tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/royal-mail.svg',
            ],
            'postnl'      => [
                'label'       => \__( 'PostNL', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://jouw.postnl.nl/track-and-trace/{tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'postnord'    => [
                'label'       => \__( 'PostNord', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.postnord.com/track-and-trace/?id={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'australia_post' => [
                'label'       => \__( 'Australia Post', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://auspost.com.au/mypost/track/#/details/{tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'ups_us'      => [
                'label'       => \__( 'UPS US', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.ups.com/track?loc=en_US&Requester=KITGENIX.COM&tracknum={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'usps'        => [
                'label'       => \__( 'USPS', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'ups_ie'      => [
                'label'       => \__( 'UPS Ireland', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.ups.com/track?loc=en_IE&Requester=KITGENIX.COM&tracknum={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'ups_uk'      => [
                'label'       => \__( 'UPS UK', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'https://www.ups.com/track?loc=en_GB&Requester=KITGENIX.COM&tracknum={tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
            'yodel'       => [
                'label'       => \__( 'Yodel', 'kitgenix-order-tracking-for-woocommerce' ),
                'url_pattern' => 'http://yodel.co.uk/tracking/{tracking_number}',
                'icon'        => KITGENIX_ORDER_TRACKING_FOR_WOOCOMMERCE_PLUGIN_URL . 'assets/images/carriers/other.svg',
            ],
        ];
    }

    /**
     * Return the built-in carrier registry (no custom carriers merged).
     *
     * @return array<string,array<string,string>>
     */
    public static function builtin(): array {
        return self::all();
    }

    /**
     * Get carrier settings stored in the database.
     *
     * @return array{builtin_disabled:array<int,string>,builtin_priority:array<string,int>,custom:array<int,array{key:string,label:string,url_pattern:string,icon:string,enabled:bool,priority:int}>,carrier_pack:string}
     */
    public static function get_settings(): array {
        $raw = \get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }

        $packs = self::pack_options();
        $carrier_pack = isset( $raw['carrier_pack'] ) ? sanitize_key( (string) $raw['carrier_pack'] ) : self::recommended_pack();
        if ( ! isset( $packs[ $carrier_pack ] ) ) {
            $carrier_pack = self::recommended_pack();
        }

        $builtin_disabled = [];
        if ( isset( $raw['builtin_disabled'] ) && is_array( $raw['builtin_disabled'] ) ) {
            foreach ( $raw['builtin_disabled'] as $k ) {
                $k = is_string( $k ) ? sanitize_key( $k ) : '';
                if ( $k !== '' ) {
                    $builtin_disabled[] = $k;
                }
            }
        }

        $builtin_priority = [];
        if ( isset( $raw['builtin_priority'] ) && is_array( $raw['builtin_priority'] ) ) {
            foreach ( $raw['builtin_priority'] as $k => $v ) {
                $k = is_string( $k ) ? sanitize_key( $k ) : '';
                if ( $k === '' ) {
                    continue;
                }

                if ( is_scalar( $v ) || ( is_object( $v ) && method_exists( $v, '__toString' ) ) ) {
                    $builtin_priority[ $k ] = (int) $v;
                }
            }
        }

        $custom = [];
        if ( isset( $raw['custom'] ) && is_array( $raw['custom'] ) ) {
            foreach ( $raw['custom'] as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }

                $key = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
                $label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
                $url_pattern = isset( $row['url_pattern'] ) ? sanitize_text_field( (string) $row['url_pattern'] ) : '';
                $icon = isset( $row['icon'] ) ? esc_url_raw( (string) $row['icon'] ) : '';
                $enabled = isset( $row['enabled'] ) ? (bool) $row['enabled'] : true;
                $priority = isset( $row['priority'] ) ? (int) $row['priority'] : 0;

                if ( $label === '' ) {
                    continue;
                }

                if ( $key === '' ) {
                    $key = sanitize_key( $label );
                }

                if ( $key === '' ) {
                    continue;
                }

                $custom[] = [
                    'key'         => $key,
                    'label'       => $label,
                    'url_pattern' => $url_pattern,
                    'icon'        => $icon,
                    'enabled'     => $enabled,
                    'priority'    => $priority,
                ];
            }
        }

        return [
            'builtin_disabled' => array_values( array_unique( $builtin_disabled ) ),
            'builtin_priority' => $builtin_priority,
            'custom'           => $custom,
            'carrier_pack'     => $carrier_pack,
        ];
    }

    /**
     * Persist carrier settings.
     *
    * @param array{builtin_disabled:array<int,string>,builtin_priority?:array<string,int>,custom:array<int,array{key:string,label:string,url_pattern:string,icon:string,enabled:bool,priority?:int}>,carrier_pack?:string} $settings
     */
    public static function update_settings( array $settings ): void {
        $current_settings = self::get_settings();
        $packs            = self::pack_options();

        $builtin_disabled = [];
        if ( isset( $settings['builtin_disabled'] ) && is_array( $settings['builtin_disabled'] ) ) {
            foreach ( $settings['builtin_disabled'] as $k ) {
                $k = is_string( $k ) ? sanitize_key( $k ) : '';
                if ( $k !== '' ) {
                    $builtin_disabled[] = $k;
                }
            }
        }

        $builtin_priority = [];
        if ( isset( $settings['builtin_priority'] ) && is_array( $settings['builtin_priority'] ) ) {
            foreach ( $settings['builtin_priority'] as $k => $v ) {
                $k = is_string( $k ) ? sanitize_key( $k ) : '';
                if ( $k === '' ) {
                    continue;
                }

                if ( is_scalar( $v ) || ( is_object( $v ) && method_exists( $v, '__toString' ) ) ) {
                    $builtin_priority[ $k ] = (int) $v;
                }
            }
        }

        $custom = [];
        if ( isset( $settings['custom'] ) && is_array( $settings['custom'] ) ) {
            foreach ( $settings['custom'] as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }

                $key = isset( $row['key'] ) ? sanitize_key( (string) $row['key'] ) : '';
                $label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
                $url_pattern = isset( $row['url_pattern'] ) ? sanitize_text_field( (string) $row['url_pattern'] ) : '';
                $icon = isset( $row['icon'] ) ? esc_url_raw( (string) $row['icon'] ) : '';
                $enabled = isset( $row['enabled'] ) ? (bool) $row['enabled'] : true;
                $priority = isset( $row['priority'] ) ? (int) $row['priority'] : 0;

                if ( $label === '' ) {
                    continue;
                }

                if ( $key === '' ) {
                    $key = sanitize_key( $label );
                }

                if ( $key === '' ) {
                    continue;
                }

                $custom[] = [
                    'key'         => $key,
                    'label'       => $label,
                    'url_pattern' => $url_pattern,
                    'icon'        => $icon,
                    'enabled'     => $enabled,
                    'priority'    => $priority,
                ];
            }
        }

        $carrier_pack = isset( $settings['carrier_pack'] ) ? sanitize_key( (string) $settings['carrier_pack'] ) : (string) ( $current_settings['carrier_pack'] ?? self::recommended_pack() );
        if ( ! isset( $packs[ $carrier_pack ] ) ) {
            $carrier_pack = self::recommended_pack();
        }

        \update_option(
            self::OPTION_KEY,
            [
                'builtin_disabled' => array_values( array_unique( $builtin_disabled ) ),
                'builtin_priority' => $builtin_priority,
                'custom'           => $custom,
                'carrier_pack'     => $carrier_pack,
            ],
            false
        );
    }

    /**
     * Build the full carrier registry (built-in + custom).
     *
     * @return array<string,array<string,string>>
     */
    public static function registry(): array {
        $out = self::builtin();
        $settings = self::get_settings();
        $builtin_keys = array_keys( $out );

        foreach ( $settings['custom'] as $row ) {
            $key = $row['key'];
            if ( $key === '' ) {
                continue;
            }
            // Prevent collisions with built-in keys.
            if ( in_array( $key, $builtin_keys, true ) ) {
                $key = 'custom_' . $key;
            }

            $out[ $key ] = [
                'label'       => $row['label'],
                'url_pattern' => $row['url_pattern'],
                'icon'        => $row['icon'],
            ];
        }

        return $out;
    }

    /**
     * Return carriers that should be selectable in the admin UI (enabled built-ins + enabled custom carriers).
     *
     * @return array<string,array<string,string>>
     */
    public static function selectable(): array {
        $builtin = self::builtin();
        $settings = self::get_settings();

        $disabled = array_fill_keys( $settings['builtin_disabled'], true );
        $out = [];
        $sortable = [];
        foreach ( $builtin as $key => $carrier ) {
            // Always keep a fallback carrier to avoid dead-ends.
            if ( $key !== 'other' && isset( $disabled[ $key ] ) ) {
                continue;
            }

            $label = $carrier['label'] ?? $key;
            $priority = 9999;
            if ( isset( $settings['builtin_priority'][ $key ] ) ) {
                $maybe_priority = (int) $settings['builtin_priority'][ $key ];
                // Treat 0 as "unset" so that 1 truly means "first".
                if ( $maybe_priority !== 0 ) {
                    $priority = $maybe_priority;
                }
            }
            if ( $key === 'other' ) {
                // Default fallback carrier to the end unless explicitly prioritised.
                $priority = isset( $settings['builtin_priority'][ $key ] ) && ( (int) $settings['builtin_priority'][ $key ] ) !== 0
                    ? (int) $settings['builtin_priority'][ $key ]
                    : 10000;
            }

            $out[ $key ] = $carrier;
            $sortable[ $key ] = [
                'label'    => (string) $label,
                'priority' => $priority,
            ];
        }

        $builtin_keys = array_keys( $builtin );
        foreach ( $settings['custom'] as $row ) {
            if ( empty( $row['enabled'] ) ) {
                continue;
            }

            $key = $row['key'];
            if ( $key === '' ) {
                continue;
            }
            if ( in_array( $key, $builtin_keys, true ) ) {
                $key = 'custom_' . $key;
            }

            $out[ $key ] = [
                'label'       => $row['label'],
                'url_pattern' => $row['url_pattern'],
                'icon'        => $row['icon'],
            ];

            $sortable[ $key ] = [
                'label'    => (string) $row['label'],
                'priority' => ( isset( $row['priority'] ) && ( (int) $row['priority'] ) !== 0 ) ? (int) $row['priority'] : 9999,
            ];
        }

        $keys = array_keys( $out );
        usort(
            $keys,
            function ( string $key_a, string $key_b ) use ( $sortable ): int {
                $a = $sortable[ $key_a ] ?? [ 'priority' => 0, 'label' => $key_a ];
                $b = $sortable[ $key_b ] ?? [ 'priority' => 0, 'label' => $key_b ];

                // Lower numbers appear first (1 = top). Unset/0 is treated as "bottom".
                $prio_cmp = ( (int) $a['priority'] ) <=> ( (int) $b['priority'] );
                if ( $prio_cmp !== 0 ) {
                    return $prio_cmp;
                }

                return strcasecmp( (string) $a['label'], (string) $b['label'] );
            }
        );

        $sorted = [];
        foreach ( $keys as $k ) {
            if ( isset( $out[ $k ] ) ) {
                $sorted[ $k ] = $out[ $k ];
            }
        }

        return $sorted;
    }

    /**
     * Get all known carrier keys (built-in + custom).
     *
     * @return array<int,string>
     */
    public static function keys_all(): array {
        return array_keys( self::registry() );
    }

    /**
     * Get the human-readable carrier label.
     *
     * @param string $key Carrier key.
     *
     * @return string
     */
    public static function get_label( string $key ): string {
        $carriers = self::registry();

        return $carriers[ $key ]['label'] ?? $key;
    }

    /**
     * Get the icon URL for a carrier.
     *
     * @param string $key Carrier key.
     *
     * @return string
     */
    public static function get_icon( string $key ): string {
        $carriers = self::registry();

        return $carriers[ $key ]['icon'] ?? '';
    }

    /**
     * Get the raw URL pattern for a carrier.
     *
     * @param string $key Carrier key.
     *
     * @return string
     */
    public static function get_url_pattern( string $key ): string {
        $carriers = self::registry();

        return $carriers[ $key ]['url_pattern'] ?? '';
    }

    /**
     * Build a concrete tracking URL for a given carrier + tracking number.
     *
     * @param string $key             Carrier key.
     * @param string $tracking_number Tracking number.
     *
     * @return string Tracking URL or empty string if not available.
     */
    public static function build_tracking_url( string $key, string $tracking_number ): string {
        $pattern = self::get_url_pattern( $key );

        if ( '' === $pattern ) {
            return '';
        }

        return str_replace( '{tracking_number}', rawurlencode( $tracking_number ), $pattern );
    }
}
