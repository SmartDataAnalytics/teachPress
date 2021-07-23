<?php
/**
 * This file contains the database access class for publications
 * @package teachpress
 * @subpackage core
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPLv2 or later
 */

/**
 * Contains functions for getting, adding and deleting of publications
 * @package teachpress
 * @subpackage database
 * @since 5.0.0
 */
class TP_Publications {
    
    /**
     * Returns a single publication
     * @param int $id               The publication ID
     * @param string $output_type   OBJECT, ARRAY_N or ARRAY_A, default is OBJECT
     * @return mixed
     * @since 5.0.0
     */
    public static function get_publication($id, $output_type = OBJECT) {
        global $wpdb;
        $result = $wpdb->get_row("SELECT *, DATE_FORMAT(date, '%Y') AS year FROM " . TEACHPRESS_PUB . " WHERE `pub_id` = '" . intval($id) . "'", $output_type);
        return $result;
    }
    
    /**
     * Returns a single publication selected by BibTeX key
     * @param int $key              The BibTeX key
     * @param string $output_type   OBJECT, ARRAY_N or ARRAY_A, default is OBJECT
     * @return mixed
     * @since 5.0.0
     */
    public static function get_publication_by_key($key, $output_type = OBJECT) {
        global $wpdb;
        $key = esc_sql(htmlspecialchars($key));
        $result = $wpdb->get_row("SELECT *, DATE_FORMAT(date, '%Y') AS year FROM " . TEACHPRESS_PUB . " WHERE `bibtex` = '$key'", $output_type);
        return $result;
    }
    
    /**
     * Returns an array or object of publications
     * 
     * Possible values for the array $args:
     *  user (STRING)                   User IDs (separated by comma)
     *  type (STRING)                   Type name (separated by comma)
     *  tag (STRING)                    Tag IDs (separated by comma)
     *  author_id (STRING)              Author IDs (separated by comma)
     *  import_id (STRING)              Import IDs (separated by comma)
     *  year (STRING)                   Years (separated by comma)
     *  author (STRING)                 Author name (separated by comma)
     *  editor (STRING)                 Editor name (separated by comma)
     *  exclude (STRING)                The ids of the publications you want to exclude (separated by comma)
     *  include (STRING)                The ids of the publications you want to include (separated by comma)
     *  include_editor_as_author (BOOL) True or false
     *  exclude_tags (STRING)           Use it to exclude publications via tag IDs (separated by comma)
     *  order (STRING)                  The order of the list
     *  limit (STRING)                  The sql search limit, ie: 0,30
     *  search (STRING)                 The search string
     *  output_type (STRING)            OBJECT, ARRAY_N or ARRAY_A, default is OBJECT
     *
     * @since 5.0.0
     * @param array $args
     * @param boolean $count    set to true if you only need the number of rows
     * @return mixed            array, object or int
    */
    public static function get_publications($args = array(), $count = false) {
        $defaults = array(
            'user'                      => '',
            'type'                      => '',
            'tag'                       => '',
            'author_id'                 => '', 
            'import_id'                 => '',
            'year'                      => '',
            'author'                    => '',
            'editor'                    => '',
            'include'                   => '',
            'include_editor_as_author'  => true,
            'exclude'                   => '',
            'exclude_tags'              => '',
            'exclude_types'             => '',
            'order'                     => 'date DESC',
            'limit'                     => '',
            'search'                    => '',
            'meta_key_search'           => array(), //@SHAHAB: new meta_key_search as array of (meta_key => meta_value) pair that's initialized in shortcode builder sql_parameter & args for getting list of pubs
            'output_type'               => OBJECT
        );
        $atts = wp_parse_args( $args, $defaults );
        global $wpdb;

        // define all things for meta data integration
        $joins = '';
        $selects = '';
        $meta_fields = $wpdb->get_results("SELECT variable FROM " . TEACHPRESS_SETTINGS . " WHERE category = 'teachpress_pub'", ARRAY_A);
        if ( !empty($meta_fields) ) {
            $i = 1;
            foreach ($meta_fields as $field) {
                $table_id = 'm' . $i; 
                $selects .= ', ' . $table_id .'.meta_value AS ' . $field['variable'];
                $joins .= ' LEFT JOIN ' . TEACHPRESS_PUB_META . ' ' . $table_id . " ON ( " . $table_id . ".pub_id = p.pub_id AND " . $table_id . ".meta_key = '" . $field['variable'] . "' ) ";
                $i++;
            }
        }

        // define basics
        $select = "SELECT DISTINCT p.pub_id, p.title, p.type, p.bibtex, p.author, p.editor, p.date, DATE_FORMAT(p.date, '%Y') AS year, p.urldate, p.isbn, p.url, p.booktitle, p.issuetitle, p.journal, p.volume, p.number, p.pages, p.publisher, p.address, p.edition, p.chapter, p.institution, p.organization, p.school, p.series, p.crossref, p.abstract, p.howpublished, p.key, p.techtype, p.note, p.is_isbn, p.image_url, p.image_target, p.image_ext, p.doi, p.rel_page, p.status, p.added, p.modified, p.import_id $selects FROM " . TEACHPRESS_PUB . " p $joins ";
        $join = '';

        // exclude publications via tag_id
        if ( $atts['exclude_tags'] != '' ) {
            $extend = '';
            $exclude_tags = TP_DB_Helpers::generate_where_clause($atts['exclude_tags'], "tag_id", "OR", "=");
            $exclude_publications = $wpdb->get_results("SELECT DISTINCT pub_id FROM " . TEACHPRESS_RELATION . " WHERE $exclude_tags ORDER BY pub_id ASC", ARRAY_A);
            foreach ($exclude_publications as $row) {
                $extend .= $row['pub_id'] . ',';
            }
            $atts['exclude'] = $extend . $atts['exclude'];
        }

        // additional joins
        if ( $atts['user'] != '' ) {
            $join .= "INNER JOIN " . TEACHPRESS_USER . " u ON u.pub_id = p.pub_id ";
        }
        if ( $atts['tag'] != '' ) {
            $join .= "INNER JOIN " . TEACHPRESS_RELATION . " b ON p.pub_id = b.pub_id INNER JOIN " . TEACHPRESS_TAGS . " t ON t.tag_id = b.tag_id ";
        }
        if ( $atts['author_id'] != '' ) {
            $join .= "INNER JOIN " . TEACHPRESS_REL_PUB_AUTH . " r ON p.pub_id = r.pub_id ";
        }

        // define order_by clause
        $order = '';
        $array = explode(",", esc_sql( $atts['order'] ) );
        foreach($array as $element) {
            $element = trim($element);
            // order by year
            if ( strpos($element, 'year') !== false ) {
                $order = $order . $element . ', ';
            }
            // normal case
            if ( $element != '' && strpos($element, 'year') === false ) {
                $order = $order . 'p.' . $element . ', ';
            }
        }
        if ( $order != '' ) {
            $order = substr($order, 0, -2);
        }

        // define global search
        $search = esc_sql(htmlspecialchars(stripslashes($atts['search'])));
        if ( $search != '' ) {
            $search = "p.title LIKE '%$search%' OR p.author LIKE '%$search%' OR p.editor LIKE '%$search%' OR p.isbn LIKE '%$search%' OR p.booktitle LIKE '%$search%' OR p.issuetitle LIKE '%$search%' OR p.journal LIKE '%$search%' OR p.date LIKE '%$search%' OR p.abstract LIKE '%$search%' OR p.note LIKE '%$search%'";
        }
        
        // WHERE clause
        $nwhere = array();
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['exclude'], "p.pub_id", "AND", "!=");
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['exclude_types'], "p.type", "AND", "!=");
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['include'], "p.pub_id", "OR", "=");
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['type'], "p.type", "OR", "=");
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['user'], "u.user", "OR", "=");
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['tag'], "b.tag_id", "OR", "=");
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['author_id'], "r.author_id", "OR", "=");
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['import_id'], "p.import_id", "OR", "=");
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['editor'], "p.editor", "OR", "LIKE", '%');
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['author'], "p.author", "OR", "LIKE", '%');
        $nwhere[] = ( $atts['author_id'] != '' && $atts['include_editor_as_author'] === false) ? " AND ( r.is_author = 1 ) " : null;
        $nwhere[] = ( $search != '') ? $search : null;

        $nhaving = array(); //@shahab: new having variable as array instead of string to make room for other havings (e.g. metadata which is resolved by TP's official where clause)
        if ( !empty( $meta_fields ) && !empty( $atts['meta_key_search'] ) ) {
            $meta_key_search = $atts['meta_key_search'];
            $i = 1;
            // Go throw each meta field and if there is a search value for it in meta_key_search[], add it to the WHERE clause
            foreach ($meta_fields as $field) {
                $key = $field['variable'];
                $column_id = 'm' . $i . '.meta_value'; 
                if (array_key_exists($key, $meta_key_search) ) {
                    $nwhere[] = TP_DB_Helpers::generate_where_clause($meta_key_search[$key], $column_id, "OR", "=");
                    //$nhaving[] = TP_DB_Helpers::generate_where_clause($meta_key_search[$key], $key, "OR", "="); //@shahab: make HAVING clause for metadata
                }
                $i++;
            }
        }
        $where = TP_DB_Helpers::compose_clause($nwhere);
        
        // HAVING clause
        if ( $atts['year'] != '' && $atts['year'] !== '0' ) {
            $nhaving[] = TP_DB_Helpers::generate_where_clause($atts['year'], "year", "OR", "=");
        }
        
        //@Shahab: compose HAVING of all clauses
        $having = TP_DB_Helpers::compose_clause($nhaving, 'AND', "HAVING");

        // LIMIT clause
        $limit = ( $atts['limit'] != '' ) ? 'LIMIT ' . esc_sql($atts['limit']) : '';

        // End
        if ( $count !== true ) {
            $sql = $select . $join . $where . $having . " ORDER BY $order $limit";
        }
        else {
            $sql = "SELECT COUNT( DISTINCT pub_id ) AS `count` FROM ( $select $join $where $having) p ";
        }
        
        // print_r($args);
        // get_tp_message($sql,'red');
        $sql = ( $count != true ) ? $wpdb->get_results($sql, $atts['output_type']): $wpdb->get_var($sql);
        return $sql;
    }
    
    /**
     * Returns publications meta data
     * @param int $pub_id           The publication ID
     * @param string $meta_key      The name of the meta field
     * @return array
     * @since 5.0.0
     */
    public static function get_pub_meta($pub_id, $meta_key = ''){
        global $wpdb;
        $where = '';
        if ( $meta_key !== '' ) {
            $where = "AND `meta_key` = '" . esc_sql($meta_key) . "'";
        }
        $sql = "SELECT * FROM " . TEACHPRESS_PUB_META . " WHERE `pub_id` = '" . intval($pub_id) . "' $where";
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Returns an array or object of users who has a publication list
     * 
     * Possible values for the array $args:
     *       output type (STRING)     OBJECT, ARRAY_A, ARRAY_N, default is OBJECT
     * 
     * @param array $args
     * @return object|array
     * @since 5.0.0
     */
    public static function get_pub_users( $args = array() ) {
        $defaults = array(
            'output_type' => OBJECT
        ); 
        $atts = wp_parse_args( $args, $defaults );

        global $wpdb;
        $result = $wpdb->get_results("SELECT DISTINCT user FROM " . TEACHPRESS_USER, $atts['output_type']);

        return $result;
    }
    
    /**
     * Returns an array or object of publication types which are used for existing publication entries
     * 
     * Possible values for the array $args:
     *       user (STRING)            User IDs (separated by comma)
     *       include (STRING)         Publication types (separated by comma)
     *       exclude (STRING)         Publication types (separated by comma)
     *       output type (STRING)     OBJECT, ARRAY_A, ARRAY_N, default is ARRAY_A
     * 
     * @param array $args
     * @return object|array
     * @since 5.0.0
     */
    public static function get_used_pubtypes( $args = array() ) {
        $defaults = array(
            'user'          => '',
            'include'       => '',
            'exclude'       => '',
            'output_type'   => ARRAY_A
        ); 
        $atts = wp_parse_args( $args, $defaults );

        global $wpdb;
        $output_type = esc_sql($atts['output_type']);
        $include = TP_DB_Helpers::generate_where_clause($atts['include'], "type", "OR", "=");
        $exclude = TP_DB_Helpers::generate_where_clause($atts['exclude'], "type", "OR", "!=");
        $user = TP_DB_Helpers::generate_where_clause($atts['user'], "u.user", "OR", "=");
        
        $having = ( $include != '' || $exclude != '' ) ? " HAVING $include $exclude " : "";
        
        if ( $user == '' ) {
            return $wpdb->get_results("SELECT DISTINCT p.type FROM " .TEACHPRESS_PUB . " p $having ORDER BY p.type ASC", $output_type);
        }
        
        return $wpdb->get_results("SELECT DISTINCT p.type AS type from " .TEACHPRESS_PUB . " p 
                                    INNER JOIN " .TEACHPRESS_USER . " u ON u.pub_id=p.pub_id 
                                    WHERE $user 
                                    $having
                                    ORDER BY p.type ASC", $output_type);
       
    }
    
    /**
     * Returns an object or array with the years where publications are written
     * 
     * Possible values for the array $args:
     *       type (STRING)            Publication types (separated by comma)
     *       user (STRING)            User IDs (separated by comma)
     *       order (STRING)           ASC or DESC; default is ASC
     *       output type (STRING)     OBJECT, ARRAY_A, ARRAY_N, default is OBJECT
     * 
     * @param array $args
     * @return object|array
     * @since 5.0.0
     */
    public static function get_years( $args = array() ) {
        $defaults = array(
            'type'          => '',
            'user'          => '',
            'include'       => '',
            'order'         => 'ASC',
            'output_type'   => OBJECT
        ); 
        $atts = wp_parse_args( $args, $defaults );

        global $wpdb;

        // JOIN
        $join = ( $atts['user']) ? "INNER JOIN " . TEACHPRESS_USER . " u ON u.pub_id = p.pub_id" : '';
        
        // WHERE clause
        $nwhere = array();
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['type'], "p.type", "OR", "=");
        $nwhere[] = TP_DB_Helpers::generate_where_clause($atts['user'], "u.user", "OR", "=");
        $where = TP_DB_Helpers::compose_clause($nwhere);
        
        // HAVING clause
        $having = '';
        if ( $atts['include'] != '' && $atts['include'] !== '0' ) {
            $having = ' HAVING ' . TP_DB_Helpers::generate_where_clause($atts['include'], "year", "OR", "=");
        }

        // END
        $order = esc_sql($atts['order']);
        $result = $wpdb->get_results("SELECT DISTINCT DATE_FORMAT(p.date, '%Y') AS year FROM " . TEACHPRESS_PUB . " p $join $where $having ORDER BY year $order", $atts['output_type']);
        return $result;
    }
    
    /** 
     * Adds a publication
     * @param array $data       An associative array of publication data (title, type, bibtex, author, editor,...)
     * @param string $tags      An associative array of tags
     * @param array $bookmark   An associative array of bookmark IDs
     * @return int              The ID of the new publication
     * @since 5.0.0
    */
    public static function add_publication($data, $tags, $bookmark) {
        global $wpdb;
        $defaults = self::get_default_fields();
        $post_time = current_time('mysql',0);
        $data = wp_parse_args( $data, $defaults );
        
        // check last chars of author/editor fields
        if ( substr($data['author'], -5) === ' and ' ) {
            $data['author'] = substr($data['author'] ,0 , strlen($data['author']) - 5);
        }
        if ( substr($data['editor'], -5) === ' and ' ) {
            $data['editor'] = substr($data['editor'] ,0 , strlen($data['editor']) - 5);
        }
        
        // replace double spaces from author/editor fields
        $data['author'] = str_replace('  ', ' ', $data['author']);
        $data['editor'] = str_replace('  ', ' ', $data['editor']);

        $wpdb->insert( 
            TEACHPRESS_PUB, 
            array( 
                'title'         => ( $data['title'] === '' ) ? '[' . __('No title','teachpress') . ']' : stripslashes($data['title']), 
                'type'          => $data['type'], 
                'bibtex'        => stripslashes( TP_Publications::generate_unique_bibtex_key($data['bibtex']) ), 
                'author'        => stripslashes($data['author']), 
                'editor'        => stripslashes($data['editor']), 
                'isbn'          => $data['isbn'], 
                'url'           => $data['url'], 
                'date'          => ( $data['date'] == 'JJJJ-MM-TT' ) ? '0000-00-00' : $data['date'], 
                'urldate'       => ( $data['urldate'] == 'JJJJ-MM-TT' ) ? '0000-00-00' : $data['urldate'], 
                'booktitle'     => stripslashes($data['booktitle']), 
                'issuetitle'    => stripslashes($data['issuetitle']), 
                'journal'       => stripslashes($data['journal']), 
                'volume'        => $data['volume'], 
                'number'        => $data['number'], 
                'pages'         => $data['pages'], 
                'publisher'     => stripslashes($data['publisher']), 
                'address'       => stripslashes($data['address']), 
                'edition'       => $data['edition'], 
                'chapter'       => $data['chapter'], 
                'institution'   => stripslashes($data['institution']), 
                'organization'  => stripslashes($data['organization']), 
                'school'        => stripslashes($data['school']), 
                'series'        => $data['series'], 
                'crossref'      => $data['crossref'], 
                'abstract'      => stripslashes($data['abstract']), 
                'howpublished'  => stripslashes($data['howpublished']), 
                'key'           => $data['key'], 
                'techtype'      => stripslashes($data['techtype']), 
                'comment'       => stripslashes($data['comment']), 
                'note'          => stripslashes($data['note']), 
                'image_url'     => $data['image_url'], 
                'image_target'  => $data['image_target'], 
                'image_ext'     => $data['image_ext'], 
                'doi'           => $data['doi'], 
                'is_isbn'       => $data['is_isbn'], 
                'rel_page'      => $data['rel_page'], 
                'status'        => stripslashes($data['status']), 
                'added'         => $post_time, 
                'modified'      => $post_time, 
                'import_id'     => $data['import_id'] ), 
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d' ) );
        $pub_id = $wpdb->insert_id;

        // Bookmarks
        if ( $bookmark != '' ) {
            $max = count( $bookmark );
            for( $i = 0; $i < $max; $i++ ) {
               if ($bookmark[$i] != '' || $bookmark[$i] != 0) {
                   TP_Bookmarks::add_bookmark($pub_id, $bookmark[$i]);
               }
            }
        }
        
        // Tags
        TP_Publications::add_relation($pub_id, $tags);
        
        // Authors
        TP_Publications::add_relation($pub_id, $data['author'], ' and ', 'authors');
        
        // Editors
        TP_Publications::add_relation($pub_id, $data['editor'], ' and ', 'editors');
        
        return $pub_id;
    }
    
    /**
     * Add publication meta data
     * @param int $pub_id           The publication Id
     * @param string $meta_key      The name of the meta field
     * @param string $meta_value    The value of the meta field
     * @since 5.0.0
     */
    public static function add_pub_meta ($pub_id, $meta_key, $meta_value) {
        global $wpdb;
        $wpdb->insert( TEACHPRESS_PUB_META, array( 'pub_id' => $pub_id, 'meta_key' => $meta_key, 'meta_value' => $meta_value ), array( '%d', '%s', '%s' ) );
    }
    
    /** 
     * Edit a publication
     * @param int $pub_id           ID of the publication
     * @param array $data           An associative array with publication data
     * @param array $bookmark       An array with WP_USER_ids
     * @param array $delbox         An array with tag IDs you want to delete
     * @param string $tags          A string of Tags seperate by comma
     * @since 5.0.0
    */
   public static function change_publication($pub_id, $data, $bookmark, $delbox, $tags) {
        global $wpdb;
        $post_time = current_time('mysql',0);
        $pub_id = intval($pub_id);
        
        // check if bibtex key has no spaces
        if ( strpos($data['bibtex'], ' ') !== false ) {
            $data['bibtex'] = str_replace(' ', '', $data['bibtex']);
        }
        
        // check last chars of author/editor fields
        if ( substr($data['author'], -5) === ' and ' ) {
            $data['author'] = substr($data['author'] ,0 , strlen($data['author']) - 5);
        }
        if ( substr($data['editor'], -5) === ' and ' ) {
            $data['editor'] = substr($data['editor'] ,0 , strlen($data['editor']) - 5);
        }
        
        // replace double spaces from author/editor fields
        $data['author'] = str_replace('  ', ' ', $data['author']);
        $data['editor'] = str_replace('  ', ' ', $data['editor']);
        
        // update row
        $wpdb->update( 
                TEACHPRESS_PUB, 
                array( 
                    'title'         => stripslashes($data['title']), 
                    'type'          => $data['type'], 
                    'bibtex'        => stripslashes($data['bibtex']), 
                    'author'        => stripslashes($data['author']), 
                    'editor'        => stripslashes($data['editor']), 
                    'isbn'          => $data['isbn'], 
                    'url'           => $data['url'], 
                    'date'          => $data['date'], 
                    'urldate'       => $data['urldate'], 
                    'booktitle'     => stripslashes($data['booktitle']), 
                    'issuetitle'    => stripslashes($data['issuetitle']), 
                    'journal'       => stripslashes($data['journal']), 
                    'volume'        => $data['volume'], 
                    'number'        => $data['number'], 
                    'pages'         => $data['pages'], 
                    'publisher'     => stripslashes($data['publisher']), 
                    'address'       => stripslashes($data['address']), 
                    'edition'       => $data['edition'], 
                    'chapter'       => $data['chapter'], 
                    'institution'   => stripslashes($data['institution']), 
                    'organization'  => stripslashes($data['organization']), 
                    'school'        => stripslashes($data['school']), 
                    'series'        => $data['series'], 
                    'crossref'      => $data['crossref'], 
                    'abstract'      => stripslashes($data['abstract']), 
                    'howpublished'  => stripslashes($data['howpublished']), 
                    'key'           => $data['key'], 
                    'techtype'      => stripslashes($data['techtype']), 
                    'comment'       => stripslashes($data['comment']), 
                    'note'          => stripslashes($data['note']), 
                    'image_url'     => $data['image_url'], 
                    'image_target'  => $data['image_target'], 
                    'image_ext'     => $data['image_ext'], 
                    'doi'           => $data['doi'], 
                    'is_isbn'       => $data['is_isbn'], 
                    'rel_page'      => $data['rel_page'], 
                    'status'        => stripslashes($data['status']), 
                    'modified'      => $post_time ), 
                array( 'pub_id' => $pub_id ), 
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ,'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ), 
                array( '%d' ) );
        
        // get_tp_message($wpdb->last_query);
        
        // Bookmarks
        if ( $bookmark != '' ) {
            $max = count( $bookmark );
            for( $i = 0; $i < $max; $i++ ) {
                if ($bookmark[$i] != '' || $bookmark[$i] != 0) {
                    TP_Bookmarks::add_bookmark($pub_id, $bookmark[$i]);
                }
            }
        }
        
        // Handle tag relations
        if ( $delbox != '' ) {
            TP_Tags::delete_tag_relation($delbox);
        }
        if ( $tags != '' ) {
            TP_Publications::add_relation($pub_id, $tags);
        }
        
        // Handle author/editor relations
        TP_Authors::delete_author_relations($pub_id);
        if ( $data['author'] != '' ) {
            TP_Publications::add_relation($pub_id, $data['author'], ' and ', 'authors');
        }
        if ( $data['editor'] != '' ) {
            TP_Publications::add_relation($pub_id, $data['editor'], ' and ', 'editors');
        }
    }
    
    /**
     * Update a publication by key (import option); Returns FALSE if there is no publication with the given key
     * @param string $key       The BibTeX key
     * @param array $input_data An associative array of publication data
     * @param string $tags      An associative array of tags
     * @return boolean|int
     * @since 5.0.0
     * @version 2
     */
    public static function change_publication_by_key($key, $input_data, $tags) {
        global $wpdb;

        // Search if there is a publication with the given bibtex key
        $search_pub = self::get_publication_by_key($key, ARRAY_A);
        if ( $search_pub === NULL ) {
            return false;
        } //--- Shahab Added ---// 
        else {
            // publication already exists, no need to update. break from here!
            return $search_pub['pub_id']; 
        }
        
        // Update publication
        $data = wp_parse_args( $input_data, $search_pub );


        // ---- SHAHAB ADDED ---- 
		/* --- this is not needed anymore - to prevent updating current publications this func returns if it founds a publication */
        // prevent from deleting current URLs, (add if new: does not work properly yet!)
        /* if ( $search_pub['url'] != '' ) {
            echo $data['url'] = $search_pub['url'];
            if ( !strpos($search_pub['url'], $input_data['url'] ) ) {
                $data['url'] .= ' ' . $input_data['url'];
            }
        } */

        self::change_publication($search_pub['pub_id'], $data, '', '', '');
        
        
        // ---- SHAHAB EDITED ---- //
        // Delete existing tags -No! then commented because we need tags!
        //$wpdb->query( "DELETE FROM " . TEACHPRESS_RELATION . " WHERE `pub_id` = " . $search_pub['pub_id'] );
        
        // Add new tags
        if ( $tags != '' ) {
            TP_Publications::add_relation($search_pub['pub_id'], $tags);
        }

        return $search_pub['pub_id'];
    }
    
    /** 
     * Delete publications
     * @param array $checkbox       An array with IDs of publication
     * @since 5.0.0
    */
   public static function delete_publications($checkbox){	
        global $wpdb;
        $max = count( $checkbox );
        for( $i = 0; $i < $max; $i++ ) {
            $checkbox[$i] = intval($checkbox[$i]);
            $wpdb->query( "DELETE FROM " . TEACHPRESS_RELATION . " WHERE `pub_id` = '$checkbox[$i]'" );
            $wpdb->query( "DELETE FROM " . TEACHPRESS_REL_PUB_AUTH . " WHERE `pub_id` = $checkbox[$i]" );
            $wpdb->query( "DELETE FROM " . TEACHPRESS_USER . " WHERE `pub_id` = '$checkbox[$i]'" );
            $wpdb->query( "DELETE FROM " . TEACHPRESS_PUB_META . " WHERE `pub_id` = '$checkbox[$i]'");
            $wpdb->query( "DELETE FROM " . TEACHPRESS_PUB . " WHERE `pub_id` = '$checkbox[$i]'" );
        }
    }
    
    /**
     * Deletes course meta
     * @param int $pub_id           The publication ID
     * @param string $meta_key      The name of the meta field
     * @since 5.0.0
     */
    public static function delete_pub_meta ($pub_id, $meta_key = '') {
        global $wpdb;
        $where = '';
        if ( $meta_key !== '' ) {
            $where = "AND `meta_key` = '" . esc_sql($meta_key) . "'";
        }
        $wpdb->query("DELETE FROM " . TEACHPRESS_PUB_META . " WHERE `pub_id` = '" . intval($pub_id) . "' $where");
    }
    
    /**
     * Add new relations (for tags,authors,etc)
     * @param int $pub_id               The publication ID
     * @param string $input_string      A sting of tags
     * @param string $delimiter         The separator for the tags, Default is: ','
     * @param string $rel_type          The relation type: tags, authors or editors, default is tags
     * @since 5.0.0
     */
    public static function add_relation ($pub_id, $input_string, $delimiter = ',', $rel_type = 'tags') {
        global $wpdb;
        $pub_id = intval($pub_id);
        
        // Make sure, that there are no slashes or double htmlspecialchar encodes in the input
        $input = stripslashes( htmlspecialchars( htmlspecialchars_decode($input_string) ) );
        
        $array = explode($delimiter, $input);
        foreach($array as $element) {
            $element = trim($element);
            
            // if there is nothing in the element, go to the next one
            if ( $element === '' ) {
                continue;
            }
            
            // check if element exists
            if ( $rel_type === 'tags' ) {
                $check = $wpdb->get_var( $wpdb->prepare( "SELECT `tag_id` FROM " . TEACHPRESS_TAGS . " WHERE `name` = %s", $element ) );
            }
            else {
                $check = $wpdb->get_var( $wpdb->prepare( "SELECT `author_id` FROM " . TEACHPRESS_AUTHORS . " WHERE `name` = %s", $element ) );
            }
            
            // if element not exists
            if ( $check === NULL ){
                $check = ( $rel_type === 'tags' ) ? TP_Tags::add_tag($element) : TP_Authors::add_author( $element, TP_Bibtex::get_lastname($element) );
            }
            
            // check if relation exists, if not add relation
            if ( $rel_type === 'tags' ) {
                $test = $wpdb->query("SELECT `pub_id` FROM " . TEACHPRESS_RELATION . " WHERE `pub_id` = '$pub_id' AND `tag_id` = '$check'");
                if ( $test === 0 ) {
                    TP_Tags::add_tag_relation($pub_id, $check);
                }
            }
            else {
                $test = $wpdb->query("SELECT `pub_id` FROM " . TEACHPRESS_REL_PUB_AUTH . " WHERE `pub_id` = '$pub_id' AND `author_id` = '$check'");
                if ( $test === 0 ) {
                    $is_author = ( $rel_type === 'authors' ) ? 1 : 0;
                    $is_editor = ( $rel_type === 'editors' ) ? 1 : 0;
                    TP_Authors::add_author_relation($pub_id, $check, $is_author, $is_editor);
                }
            }
        }
    }
    
    /**
     * Generates a unique bibtex_key from a given bibtex key
     * @param string $bibtex_key
     * @return string
     * @since 6.1.1
     */
    public static function generate_unique_bibtex_key ($bibtex_key) {
        global $wpdb;
        
        if ( $bibtex_key == '' ) {
            return 'nokey';
        }
        
        // check if bibtex key has no spaces
        if ( strpos($bibtex_key, ' ') !== false ) {
            $bibtex_key = str_replace(' ', '', $bibtex_key);
        }
        
        // Check if the key is unique
        $check = $wpdb->get_var("SELECT COUNT('pub_id') FROM " . TEACHPRESS_PUB . " WHERE `bibtex` = '" . esc_sql($bibtex_key) . "'");
        if ( intval($check) > 0 ) {
            $alphabet = range('a', 'z');
            if ( $check <= 25 ) {
                $bibtex_key .= $alphabet[$check];
            }
            else {
                $bibtex_key .= '_' . $check;
            }
        }
        
        return $bibtex_key;
        
    }
    
    /**
     * Returns the default fields of a publication as array
     * @return array
     * @since 6.2.5
     */
    public static function get_default_fields () {
        return array(
            'title'             => '',
            'type'              => '',
            'bibtex'            => '',
            'author'            => '',
            'editor'            => '',
            'isbn'              => '',
            'url'               => '',
            'date'              => '',
            'urldate'           => '', 
            'booktitle'         => '',
            'issuetitle'        => '',
            'journal'           => '',
            'volume'            => '',
            'number'            => '',
            'pages'             => '',
            'publisher'         => '',
            'address'           => '',
            'edition'           => '',
            'chapter'           => '',
            'institution'       => '',
            'organization'      => '',
            'school'            => '',
            'series'            => '',
            'crossref'          => '',
            'abstract'          => '',
            'howpublished'      => '',
            'key'               => '',
            'techtype'          => '',
            'comment'           => '',
            'note'              => '',
            'image_url'         => '',
            'image_target'      => '',
            'image_ext'         => '',
            'doi'               => '',
            'is_isbn'           => '',
            'rel_page'          => '',
            'status'            => 'published',
            'import_id'         => 0
        );
    }
}

/**
 * Contains functions for getting, adding and deleting publication imports
 * @package teachpress
 * @subpackage database
 * @since 6.1.0
 */
class tp_publication_imports {
    
    /**
     * Returns a single row of the import information
     * @param int $id               ID of the table row
     * @param string $output_type     The output type, default is: ARRAY_A
     * @return array|object
     * @since 6.1
     */
    public static function get_import ($id, $output_type = ARRAY_A) {
        global $wpdb;
        $result = $wpdb->get_row("SELECT * FROM " . TEACHPRESS_PUB_IMPORTS . " WHERE `id` = '" . intval($id) . "'", $output_type);
        return $result;
    }
    
    /**
     * Returns the imports
     * @param int $wp_id            The WordPress user ID, default is: 0
     * @param string $output_type   The output type, default is: ARRAY_A
     * @return array|object
     * @since 6.1
     */
    public static function get_imports ($wp_id = 0, $output_type = ARRAY_A) {
        global $wpdb;
        
        // search only for a single user
        $where = '';
        if ( $wp_id !== 0 ) {
            $where = " WHERE `wp_id` = '" . intval($wp_id) . "'";
        }
        
        $result = $wpdb->get_results("SELECT * FROM " . TEACHPRESS_PUB_IMPORTS . $where . " ORDER BY date DESC", $output_type);
        return $result;
    }
    
    /**
     * Adds the import information
     * return int
     * @since 6.1
     */
    public static function add_import () {
        global $wpdb;
        $time = current_time('mysql',0);
        $id = get_current_user_id();
        $wpdb->insert( TEACHPRESS_PUB_IMPORTS, array( 'wp_id' => $id, 
                                                      'date' => $time ), 
                                               array( '%d', '%s') );
        return $wpdb->insert_id;
    }
    
    /**
     * Deletes the selected import information
     * @param array $checkbox       The IDs of the table rows
     * @since 6.1
     */
    public static function delete_import($checkbox) {
        global $wpdb;
        for( $i = 0; $i < count( $checkbox ); $i++ ) {
            $checkbox[$i] = intval($checkbox[$i]);
            $wpdb->query( "DELETE FROM " . TEACHPRESS_PUB_IMPORTS . " WHERE `id` = '$checkbox[$i]'" );
        }
    }
    
    /**
     * Returns an array with the number of publications for each import
     * @return array
     * @since 6.1
     */
    public static function count_publications () {
        global $wpdb;
        return $wpdb->get_results("SELECT COUNT(`pub_id`) AS number, import_id FROM " . TEACHPRESS_PUB . " WHERE import_ID > 0 GROUP BY import_id ORDER BY import_id ASC", ARRAY_A);
    }
}