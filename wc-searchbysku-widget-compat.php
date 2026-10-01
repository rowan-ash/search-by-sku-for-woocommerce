<?php

/**
 * A drop in replacement of WC_Admin_Post_Types::product_search()
 *
 * Hooks posts_search so the SKU match is added inside the search clause only.
 * Post type and post status restrictions from WP_Query still apply.
 */
add_filter('posts_search', 'product_search_sku', 11, 2);
function product_search_sku($search, $query = null)
{
    global $pagenow, $wpdb, $wp;

    if ('' === $search
        || (is_admin() && 'edit.php' != $pagenow)
        || !is_search()
        || !isset($wp->query_vars['s'])
        //s can be an array when passed as s[]=... in the query string.
        || !is_string($wp->query_vars['s'])
        //post_types can also be arrays..
        || (isset($wp->query_vars['post_type']) && is_string($wp->query_vars['post_type']) && 'product' != $wp->query_vars['post_type'])
        || (isset($wp->query_vars['post_type']) && is_array($wp->query_vars['post_type']) && !in_array('product', $wp->query_vars['post_type']))
    ) {
        return $search;
    }
    $search_ids = array();
    $terms = explode(',', $wp->query_vars['s']);

    //Hidden or disabled variations should not surface their parent on the front end.
    $variation_status = is_admin() ? '' : " and p.post_status = 'publish'";

    foreach ($terms as $term) {
        $term = trim($term);
        //Skip empty terms, e.g. a trailing comma, which would match every sku.
        if ('' === $term) {
            continue;
        }
        //Include the search by id if admin area.
        if (is_admin() && is_numeric($term)) {
            $search_ids[] = $term;
        }
        $like = '%' . $wpdb->esc_like(wc_clean($term)) . '%';

        // search for variations with a matching sku and return the parent.
        $sku_to_parent_id = $wpdb->get_col($wpdb->prepare("SELECT p.post_parent as post_id FROM {$wpdb->posts} as p join {$wpdb->postmeta} pm on p.ID = pm.post_id and pm.meta_key='_sku' and pm.meta_value LIKE %s where p.post_parent <> 0 and p.post_type = 'product_variation'{$variation_status} group by p.post_parent", $like));

        //Search for a regular product that matches the sku.
        $sku_to_id = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value LIKE %s;", $like));

        $search_ids = array_merge($search_ids, $sku_to_id, $sku_to_parent_id);
    }

    $search_ids = array_unique(array_filter(array_map('absint', $search_ids)));
    if (sizeof($search_ids) > 0) {
        $search = searchbysku_add_ids_to_search($search, "{$wpdb->posts}.ID IN (" . implode(',', $search_ids) . ")");
    }
    remove_filters_for_anonymous_class('posts_search', 'WC_Admin_Post_Types', 'product_search', 10);
    return $search;
}

/**
 * Add "OR (condition)" inside the first top level group of the search SQL.
 *
 * WP_Query builds " AND ((title LIKE ..) OR ..) " and, for logged out users,
 * appends " AND (post_password = '')", so the end of the string is not the end
 * of the search group. Quoted strings are skipped so brackets in the search
 * term do not count.
 */
function searchbysku_add_ids_to_search($search, $condition)
{
    $depth = 0;
    $quote = '';
    $length = strlen($search);
    for ($i = 0; $i < $length; $i++) {
        $char = $search[$i];
        if ('' !== $quote) {
            if ('\\' === $char) {
                $i++;
            } elseif ($char === $quote) {
                $quote = '';
            }
            continue;
        }
        if ("'" === $char || '"' === $char) {
            $quote = $char;
        } elseif ('(' === $char) {
            $depth++;
        } elseif (')' === $char) {
            $depth--;
            if (0 === $depth) {
                return substr($search, 0, $i) . " OR ({$condition})" . substr($search, $i);
            }
        }
    }
    return $search;
}
