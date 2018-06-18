<?php
class ModelExtensionModuleKnawatDropshipping extends Model {
    private $route = 'extension/module/knawat_dropshipping';

    private $languages = array();
    private $default_language_id = 1;
    private $default_language = 'en';
    private $all_stores;
    private $is_admin = false;

    public function __construct($registry){
        parent::__construct($registry);
        $this->load->language($this->route);
        $this->load->model('localisation/language');
        $this->load->model('setting/store');

        if( false !== stripos( DIR_APPLICATION, 'admin' ) ){
            $this->is_admin = true;
        }

        $this->all_stores = $this->model_setting_store->getStores();
        $this->languages = $this->model_localisation_language->getLanguages();
        $config_language = $this->config->get('config_admin_language');
        $language = explode( '-', $config_language );
        $this->default_language = $language[0];
        if( isset( $this->languages[$config_language] ) ){
            $this->default_language_id = (int)$this->languages[$config_language]['language_id'];
        }
    }

    /////////////////////////////////////////////////////////////
    ////////////////////// Product Functions ////////////////////
    /////////////////////////////////////////////////////////////
    /**
     * Partial Product Update.
     *
     */
    public function partial_update_product( $product_id, $data ) {

        $this->db->query("UPDATE " . DB_PREFIX . "product SET quantity = '" . (int)$data['quantity'] . "', stock_status_id = '" . (int)$data['stock_status_id'] . "', price = '" . (float)$data['price'] . "', date_modified = NOW() WHERE product_id = '" . (int)$product_id . "'");


        $this->db->query("DELETE FROM " . DB_PREFIX . "product_option WHERE product_id = '" . (int)$product_id . "'");
        $this->db->query("DELETE FROM " . DB_PREFIX . "product_option_value WHERE product_id = '" . (int)$product_id . "'");

        if (isset($data['product_option'])) {
            foreach ($data['product_option'] as $product_option) {
                if ($product_option['type'] == 'select' || $product_option['type'] == 'radio' || $product_option['type'] == 'checkbox' || $product_option['type'] == 'image') {
                    if (isset($product_option['product_option_value'])) {
                        $this->db->query("INSERT INTO " . DB_PREFIX . "product_option SET product_option_id = '" . (int)$product_option['product_option_id'] . "', product_id = '" . (int)$product_id . "', option_id = '" . (int)$product_option['option_id'] . "', required = '" . (int)$product_option['required'] . "'");

                        $product_option_id = $this->db->getLastId();

                        foreach ($product_option['product_option_value'] as $product_option_value) {
                            $this->db->query("INSERT INTO " . DB_PREFIX . "product_option_value SET product_option_value_id = '" . (int)$product_option_value['product_option_value_id'] . "', product_option_id = '" . (int)$product_option_id . "', product_id = '" . (int)$product_id . "', option_id = '" . (int)$product_option['option_id'] . "', option_value_id = '" . (int)$product_option_value['option_value_id'] . "', quantity = '" . (int)$product_option_value['quantity'] . "', subtract = '" . (int)$product_option_value['subtract'] . "', price = '" . (float)$product_option_value['price'] . "', price_prefix = '" . $this->db->escape($product_option_value['price_prefix']) . "', points = '" . (int)$product_option_value['points'] . "', points_prefix = '" . $this->db->escape($product_option_value['points_prefix']) . "', weight = '" . (float)$product_option_value['weight'] . "', weight_prefix = '" . $this->db->escape($product_option_value['weight_prefix']) . "'");
                        }
                    }
                } else {
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_option SET product_option_id = '" . (int)$product_option['product_option_id'] . "', product_id = '" . (int)$product_id . "', option_id = '" . (int)$product_option['option_id'] . "', value = '" . $this->db->escape($product_option['value']) . "', required = '" . (int)$product_option['required'] . "'");
                }
            }
        }

        $this->cache->delete('product');

        return $product_id;
    }

    /**
     * Get Product ID based on model
     */
    public function get_product_id_by_model( $model = '' ) {
        $temporal_sql = "SELECT product_id FROM `" . DB_PREFIX . "product` WHERE model = '".$this->db->escape($model)."' LIMIT 1";
        $result = $this->db->query( $temporal_sql );

        return !empty($result->row['product_id']) ? $result->row['product_id'] : false;
    }

    /////////////////////////////////////////////////////////////
    ////////////////////// Category Functions ///////////////////
    /////////////////////////////////////////////////////////////
    /**
     * Parse Category field.
     * Create New Category and return ID if its not there and return ID if its already exists
     *
     */
    public function parse_categories( $categories = array() ){
        if( empty( $categories ) ){
            return array();
        }

        $parse_cats = array();
        foreach ( $categories as $key => $cat ) {
            $cat_name = array_key_exists( $this->default_language, $cat ) ? $cat[$this->default_language] : $cat['en'];

            if( empty( $cat_name ) ){
                continue;
            }

            $new_cat = explode( ' / ', $cat_name );

            if( !empty( $new_cat ) ) {
                $parent_id = 0;
                $cat_number = 0;
                foreach ( $new_cat as $new_cat_name ) {
                    $temp_cat_id = 0;
                    $temp_cat_id = $this->get_category_id_by_name( $new_cat_name, $parent_id );
                    if( !$temp_cat_id ){
                        $temp_cat = array();
                        foreach ( $cat as $key => $value ) {
                            $temp_value = explode( ' / ', $value );
                            if( isset( $temp_value[$cat_number] ) ){
                                $temp_cat[$key] = $temp_value[$cat_number];
                            }
                        }
                        if( empty( $temp_cat ) ) {
                            continue;
                        }
                        $temp_cat_id = $this->create_category( $temp_cat, $parent_id );
                    }
                    $temp_cat_id = (int)$temp_cat_id;
                    $parent_id = $temp_cat_id;
                    $parse_cats[$temp_cat_id] = $temp_cat_id;
                    $cat_number++;
                }
            }
        }
        return $parse_cats;
    }

    /**
     * Create a category with tree.
     *
     */
    public function create_category( $category, $parent_id = 0 ){

        if( $this->is_admin ){
            $this->load->model('catalog/category');
        }else{
            $admin_dir = str_replace( 'system/', 'admin/', DIR_SYSTEM );
            require_once $admin_dir . "model/catalog/category.php";
            $this->model_catalog_category = new ModelCatalogCategory( $this->registry );
        }

        $temp = array(
            'category_description' => array(),
            'category_store' => array(0),
            'path' => null,
            'parent_id' => $parent_id,
            'filter' => null,
            'image' => 'no_image.jpg',
            'top' => 1,
            'column' => 1,
            'sort_order' => 0,
            'status' => 1,
        );

        if( $parent_id > 0 ){
            $temp['top'] = 0;
        }

        foreach ( $this->languages as $key => $lng ) {
            // Check for name in current language.
            $lng_code = explode( '-', $lng['code'] );
            $lng_code = $lng_code[0];

            $cat_name = array_key_exists( $lng_code, $category ) ? $category[$lng_code] : $category['en'];
            $temp['category_description'][$lng['language_id']] = array(
                'name'              => $cat_name,
                'meta_title'        => $cat_name,
                'meta_description'  => null,
                'meta_keyword'      => null,
                'description'       => null,
            );
        }

        foreach ( $this->all_stores as $key => $store ) {
            $temp['category_store'][] = $store['store_id'];
        }

        return $this->model_catalog_category->addCategory($temp);

    }

    /**
     * Get Category ID by name
     */
    public function get_category_id_by_name( $cat_name, $parent_id = 0) {

        $cat_name = htmlspecialchars($cat_name);
        $sql = "SELECT cd.* FROM " . DB_PREFIX . "category_description cd";

        if($parent_id > 0){
            $sql .= " INNER JOIN " . DB_PREFIX . "category cat ON(cd.category_id = cat.category_id AND cat.parent_id = ".$parent_id.")";
        }

        $sql .= " WHERE cd.language_id = " . (int)$this->default_language_id . " AND cd.name='".$this->db->escape( $cat_name )."' ORDER BY cd.category_id DESC";

        $query = $this->db->query($sql);

        if( !empty( $query->num_rows) ){
            return $query->rows[0]['category_id'];
        }
        return false;
    }


    /////////////////////////////////////////////////////////////
    ////////////////////// Option Functions /////////////////////
    /////////////////////////////////////////////////////////////

    /**
     * Parse Product options.
     * Create New option or option value if not exists.
     *
     */
    public function parse_product_options( $variations = array(), $price ){
        if( empty( $variations ) ){
            return array();
        }


        $product_options = array();

        if( isset( $variations ) && !empty( $variations ) ){

            $attributes = array();
            $varients = array();
            foreach ( $variations as $variation ) {

                if( isset( $variation->attributes ) && !empty( $variation->attributes ) ){
                    foreach ( $variation->attributes as $attribute ) {

                        $attribute_names = (array) $attribute->name;
                        $attribute_options = (array) $attribute->option;

                        $option_name = array_key_exists( $this->default_language, $attribute_names ) ? $attribute_names[$this->default_language] : $attribute_names['en'];
                        $option_value_name = array_key_exists( $this->default_language, $attribute_options ) ? $attribute_options[$this->default_language] : $attribute_options['en'];

                        // if option name is blank then take a chance for TR.
                        if( empty( $option_name ) ){
                            $option_name = isset( $attribute_names['tr'] ) ? $attribute_names['tr'] : '';
                        }
                        if( empty( $option_value_name ) ){
                            $option_value_name = isset( $attribute_options['tr'] ) ? $attribute_options['tr'] : '';
                        }

                        // continue if no option name found.
                        if( empty( $option_name ) ){
                            continue;
                        }

                        if( isset( $attributes[ $option_name ] ) ){
                            if( !array_key_exists( $option_value_name, $attributes[ $option_name ]['values'] ) ){
                                $attributes[ $option_name ]['values'][$option_value_name] = $attribute_options;
                            }
                        }else{
                            $attributes[ $option_name ]['name'] = $attribute_names;
                            $attributes[ $option_name ]['values'][$option_value_name] = $attribute_options;
                        }
                        $varients[ $option_name ][$option_value_name]['price'] = isset( $variation->price ) ? $variation->price : 0;
                        $varients[ $option_name ][$option_value_name]['quantity'] = isset( $variation->quantity ) ? $variation->quantity : 0;
                    }
                }
            }

            if( !empty( $attributes ) ){
                foreach ($attributes as $key => $attribute ) {

                    $temp_option = array(
                        'product_option_id'     => '',
                        'name'                  => $key,
                        'option_id'             => '',
                        'type'                  => 'select',
                        'required'              => '1',
                        'product_option_value'  => array()
                    );

                    $option_id = $this->get_option_id_by_name( $key );
                    if( !$option_id ){
                        $option_id = $this->create_option( $attribute['name'] );
                    }

                    if( !$option_id ){
                        // continue if fail to create option.
                        continue;
                    }

                    $temp_option['option_id'] = $option_id;

                    if( !empty( $attribute['values'] ) ){
                        foreach ( $attribute['values'] as $okey => $ovalue ) {

                            $temp_value = array(
                                'option_value_id'           => '',
                                'product_option_value_id'   => '',
                                'quantity'                  => $varients[$key][$okey]['quantity'],
                                'subtract'                  => '1',
                                'price_prefix'              => '+',
                                'price'                     => '0',
                                'points_prefix'             => '+',
                                'points'                    => '0',
                                'weight_prefix'             => '+',
                                'weight'                    => '0'
                            );

                            if( $price != $varients[$key][$okey]['price'] ){
                                $difference = ( $varients[$key][$okey]['price'] - $price );
                                if( $difference > 0 ){
                                    $temp_value['price_prefix'] = '+';
                                    $temp_value['price'] = $difference;
                                }elseif( $difference < 0 ){
                                    $temp_value['price_prefix'] = '-';
                                    $temp_value['price'] = -($difference);
                                }
                            }

                            $option_value_id = $this->get_option_value_id_by_name( $okey, $option_id );
                            if( !$option_value_id ){
                                $option_value_id = $this->create_option_value( $ovalue, $option_id );
                            }

                            if( !$option_value_id ){
                                // continue if fail to create option.
                                continue;
                            }

                            $temp_value['option_value_id'] = $option_value_id;
                            $temp_option['product_option_value'][] = $temp_value;
                        }
                    }

                    $product_options[] = $temp_option;
                }
            }
        }
        return $product_options;
    }


    /**
     * Get Option ID by name
     */
    public function get_option_id_by_name( $option_name, $from_option_value = false) {
        $sql = "SELECT fgd.* FROM " . DB_PREFIX . "option_description fgd WHERE name = BINARY '".$this->db->escape($option_name)."' AND language_id = " . $this->default_language_id;
        $query = $this->db->query($sql);

        if( !empty( $query->num_rows ) ){
            return $query->rows[0]['option_id'];
        }
        return false;
    }

    /**
     * Create Option.
     */
    public function create_option( $attribute_names ){
        if( empty( $attribute_names ) ){
            return false;
        }

        if( $this->is_admin ){
            $this->load->model('catalog/option');
        }else{
            $admin_dir = str_replace( 'system/', 'admin/', DIR_SYSTEM );
            require_once $admin_dir . "model/catalog/option.php";
            $this->model_catalog_option = new ModelCatalogOption( $this->registry );
        }

        $temp = array(
            'option_description'=> array(),
            'type'              => 'select',
            'sort_order'        => '0'
        );
        foreach ( $this->languages as $key => $lng ) {
            // Check for name in current language.
            $lng_code = explode( '-', $lng['code'] );
            $lng_code = $lng_code[0];

            $attr_name = array_key_exists( $lng_code, $attribute_names ) ? $attribute_names[$lng_code] : $category['en'];
            $temp['option_description'][$lng['language_id']] = array(
                'name'              => $attr_name
            );
        }

        $option_id = $this->model_catalog_option->addOption($temp);
        // Return it.
        return $option_id;
    }

    /**
     * Get option value ID by Name
     */
    public function get_option_value_id_by_name( $option_value_name, $option_id ) {
        $sql = "SELECT atd.* FROM " . DB_PREFIX . "option_value_description atd WHERE name = '".$option_value_name."' AND language_id = " . $this->default_language_id . " AND option_id = ". (int)$option_id;

        $query = $this->db->query($sql);

        if( !empty( $query->num_rows ) ){
            return ($query->rows[0]['option_value_id']) ? $query->rows[0]['option_value_id'] : false;
        }
    }

    /**
     * Create Option Value.
     */
    public function create_option_value( $option_value_names, $option_id ){
        if( empty( $option_value_names ) || empty( $option_id ) ){
            return false;
        }

        $option_value['option_value_description'] = array();
        foreach ( $this->languages as $key => $lng ) {
            // Check for name in current language.
            $lng_code = explode( '-', $lng['code'] );
            $lng_code = $lng_code[0];

            $value_name = array_key_exists( $lng_code, $option_value_names ) ? $option_value_names[$lng_code] : $option_value_names['en'];
            $option_value['option_value_description'][$lng['language_id']] = array(
                'name' => $value_name
            );
        }

        $this->db->query("INSERT INTO " . DB_PREFIX . "option_value SET option_id = '" . (int)$option_id . "', image = '', sort_order = 0");

        $option_value_id = $this->db->getLastId();

        foreach ($option_value['option_value_description'] as $language_id => $option_value_description) {
            $this->db->query("INSERT INTO " . DB_PREFIX . "option_value_description SET option_value_id = '" . (int)$option_value_id . "', language_id = '" . (int)$language_id . "', option_id = '" . (int)$option_id . "', name = '" . $this->db->escape($option_value_description['name']) . "'");
        }

        // Return it.
        return $option_value_id;
    }

}