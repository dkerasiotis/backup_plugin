<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSB_Backup_Posts {

    /**
     * Export all posts/pages/attachments to a WXR (WordPress eXtended RSS) XML file.
     *
     * @param  string $output_path Full filesystem path for the output .xml file.
     * @param  array  $post_types  Post types to include.
     * @return true|WP_Error
     */
    public function export( $output_path, $post_types = array( 'post', 'page', 'attachment' ) ) {
        $handle = fopen( $output_path, 'wb' );
        if ( ! $handle ) {
            return new WP_Error( 'file_open_failed', 'Cannot open output file for posts export.' );
        }

        $this->write_wxr_header( $handle );
        $this->write_channel_meta( $handle );
        $this->write_authors( $handle );
        $this->write_categories( $handle );
        $this->write_tags( $handle );
        $this->write_posts( $handle, $post_types );

        fwrite( $handle, "\t</channel>\n</rss>\n" );
        fclose( $handle );

        return true;
    }

    /**
     * Write the XML declaration and RSS/WXR opening tags.
     */
    private function write_wxr_header( $handle ) {
        fwrite( $handle, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" );
        fwrite( $handle, '<!-- WP Simple Backup WXR Export - ' . date( 'Y-m-d H:i:s' ) . ' -->' . "\n" );
        fwrite( $handle, '<rss version="2.0"' . "\n" );
        fwrite( $handle, "\txmlns:excerpt=\"http://wordpress.org/export/1.2/excerpt/\"\n" );
        fwrite( $handle, "\txmlns:content=\"http://purl.org/rss/1.0/modules/content/\"\n" );
        fwrite( $handle, "\txmlns:wfw=\"http://wellformedweb.org/CommentAPI/\"\n" );
        fwrite( $handle, "\txmlns:dc=\"http://purl.org/dc/elements/1.1/\"\n" );
        fwrite( $handle, "\txmlns:wp=\"http://wordpress.org/export/1.2/\"\n" );
        fwrite( $handle, ">\n<channel>\n" );
    }

    /**
     * Write RSS channel metadata.
     */
    private function write_channel_meta( $handle ) {
        fwrite( $handle, "\t<title>" . $this->cdata( get_bloginfo( 'name' ) ) . "</title>\n" );
        fwrite( $handle, "\t<link>" . esc_url( home_url() ) . "</link>\n" );
        fwrite( $handle, "\t<description>" . $this->cdata( get_bloginfo( 'description' ) ) . "</description>\n" );
        fwrite( $handle, "\t<pubDate>" . date( 'D, d M Y H:i:s +0000' ) . "</pubDate>\n" );
        fwrite( $handle, "\t<language>" . get_bloginfo( 'language' ) . "</language>\n" );
        fwrite( $handle, "\t<wp:wxr_version>1.2</wp:wxr_version>\n" );
        fwrite( $handle, "\t<wp:base_site_url>" . esc_url( site_url() ) . "</wp:base_site_url>\n" );
        fwrite( $handle, "\t<wp:base_blog_url>" . esc_url( home_url() ) . "</wp:base_blog_url>\n\n" );
    }

    /**
     * Write <wp:author> entries for all users who have authored posts.
     */
    private function write_authors( $handle ) {
        global $wpdb;

        $authors = $wpdb->get_results(
            "SELECT DISTINCT u.ID, u.user_login, u.user_email, u.display_name
             FROM {$wpdb->users} u
             INNER JOIN {$wpdb->posts} p ON u.ID = p.post_author
             WHERE p.post_status != 'auto-draft'"
        );

        foreach ( $authors as $author ) {
            fwrite( $handle, "\t<wp:author>" );
            fwrite( $handle, '<wp:author_id>' . (int) $author->ID . '</wp:author_id>' );
            fwrite( $handle, '<wp:author_login>' . $this->cdata( $author->user_login ) . '</wp:author_login>' );
            fwrite( $handle, '<wp:author_email>' . $this->cdata( $author->user_email ) . '</wp:author_email>' );
            fwrite( $handle, '<wp:author_display_name>' . $this->cdata( $author->display_name ) . '</wp:author_display_name>' );
            fwrite( $handle, "</wp:author>\n" );
        }
        fwrite( $handle, "\n" );
    }

    /**
     * Write <wp:category> entries.
     */
    private function write_categories( $handle ) {
        $categories = get_categories( array( 'hide_empty' => false ) );
        foreach ( $categories as $cat ) {
            $parent_slug = $cat->parent ? get_term( $cat->parent, 'category' )->slug : '';
            fwrite( $handle, "\t<wp:category>" );
            fwrite( $handle, '<wp:term_id>' . (int) $cat->term_id . '</wp:term_id>' );
            fwrite( $handle, '<wp:category_nicename>' . $this->cdata( $cat->slug ) . '</wp:category_nicename>' );
            fwrite( $handle, '<wp:category_parent>' . $this->cdata( $parent_slug ) . '</wp:category_parent>' );
            fwrite( $handle, '<wp:cat_name>' . $this->cdata( $cat->name ) . '</wp:cat_name>' );
            fwrite( $handle, "</wp:category>\n" );
        }
    }

    /**
     * Write <wp:tag> entries.
     */
    private function write_tags( $handle ) {
        $tags = get_tags( array( 'hide_empty' => false ) );
        foreach ( $tags as $tag ) {
            fwrite( $handle, "\t<wp:tag>" );
            fwrite( $handle, '<wp:term_id>' . (int) $tag->term_id . '</wp:term_id>' );
            fwrite( $handle, '<wp:tag_slug>' . $this->cdata( $tag->slug ) . '</wp:tag_slug>' );
            fwrite( $handle, '<wp:tag_name>' . $this->cdata( $tag->name ) . '</wp:tag_name>' );
            fwrite( $handle, "</wp:tag>\n" );
        }
        fwrite( $handle, "\n" );
    }

    /**
     * Write all post <item> entries, batched to avoid memory issues.
     *
     * @param resource $handle     File handle.
     * @param array    $post_types Post types to export.
     */
    private function write_posts( $handle, $post_types ) {
        $page      = 1;
        $per_page  = 100;

        while ( true ) {
            $query = new WP_Query( array(
                'post_type'      => $post_types,
                'post_status'    => 'any',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => false,
            ) );

            if ( ! $query->have_posts() ) {
                break;
            }

            while ( $query->have_posts() ) {
                $query->the_post();
                $this->write_post_item( $handle, get_post() );
            }

            wp_reset_postdata();

            if ( $page >= $query->max_num_pages ) {
                break;
            }

            $page++;
        }
    }

    /**
     * Write a single post as a WXR <item> element.
     *
     * @param resource $handle File handle.
     * @param WP_Post  $post   Post object.
     */
    private function write_post_item( $handle, WP_Post $post ) {
        $author = get_userdata( $post->post_author );

        fwrite( $handle, "\n\t<item>\n" );
        fwrite( $handle, "\t\t<title>" . $this->cdata( $post->post_title ) . "</title>\n" );
        fwrite( $handle, "\t\t<link>" . esc_url( get_permalink( $post ) ) . "</link>\n" );
        fwrite( $handle, "\t\t<pubDate>" . mysql2date( 'D, d M Y H:i:s +0000', $post->post_date_gmt ) . "</pubDate>\n" );
        fwrite( $handle, "\t\t<dc:creator>" . $this->cdata( $author ? $author->user_login : '' ) . "</dc:creator>\n" );
        fwrite( $handle, "\t\t<guid isPermaLink=\"false\">" . esc_url( $post->guid ) . "</guid>\n" );
        fwrite( $handle, "\t\t<description></description>\n" );
        fwrite( $handle, "\t\t<content:encoded>" . $this->cdata( $post->post_content ) . "</content:encoded>\n" );
        fwrite( $handle, "\t\t<excerpt:encoded>" . $this->cdata( $post->post_excerpt ) . "</excerpt:encoded>\n" );

        fwrite( $handle, "\t\t<wp:post_id>" . (int) $post->ID . "</wp:post_id>\n" );
        fwrite( $handle, "\t\t<wp:post_date>" . $this->cdata( $post->post_date ) . "</wp:post_date>\n" );
        fwrite( $handle, "\t\t<wp:post_date_gmt>" . $this->cdata( $post->post_date_gmt ) . "</wp:post_date_gmt>\n" );
        fwrite( $handle, "\t\t<wp:comment_status>" . $this->cdata( $post->comment_status ) . "</wp:comment_status>\n" );
        fwrite( $handle, "\t\t<wp:ping_status>" . $this->cdata( $post->ping_status ) . "</wp:ping_status>\n" );
        fwrite( $handle, "\t\t<wp:post_name>" . $this->cdata( $post->post_name ) . "</wp:post_name>\n" );
        fwrite( $handle, "\t\t<wp:status>" . $this->cdata( $post->post_status ) . "</wp:status>\n" );
        fwrite( $handle, "\t\t<wp:post_parent>" . (int) $post->post_parent . "</wp:post_parent>\n" );
        fwrite( $handle, "\t\t<wp:menu_order>" . (int) $post->menu_order . "</wp:menu_order>\n" );
        fwrite( $handle, "\t\t<wp:post_type>" . $this->cdata( $post->post_type ) . "</wp:post_type>\n" );
        fwrite( $handle, "\t\t<wp:post_password>" . $this->cdata( $post->post_password ) . "</wp:post_password>\n" );
        fwrite( $handle, "\t\t<wp:is_sticky>" . ( is_sticky( $post->ID ) ? '1' : '0' ) . "</wp:is_sticky>\n" );

        // Taxonomy terms
        $taxonomies = get_object_taxonomies( $post->post_type );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_the_terms( $post->ID, $taxonomy );
            if ( $terms && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    fwrite( $handle, "\t\t<category domain=\"{$taxonomy}\" nicename=\"" . esc_attr( $term->slug ) . '">' . $this->cdata( $term->name ) . "</category>\n" );
                }
            }
        }

        // Post meta
        $meta = get_post_meta( $post->ID );
        if ( $meta ) {
            foreach ( $meta as $key => $values ) {
                foreach ( $values as $value ) {
                    fwrite( $handle, "\t\t<wp:postmeta>\n" );
                    fwrite( $handle, "\t\t\t<wp:meta_key>" . $this->cdata( $key ) . "</wp:meta_key>\n" );
                    fwrite( $handle, "\t\t\t<wp:meta_value>" . $this->cdata( $value ) . "</wp:meta_value>\n" );
                    fwrite( $handle, "\t\t</wp:postmeta>\n" );
                }
            }
        }

        fwrite( $handle, "\t</item>\n" );
    }

    /**
     * Wrap a string in a CDATA section, handling nested CDATA correctly.
     *
     * @param  string $value Raw string.
     * @return string        CDATA-wrapped string.
     */
    private function cdata( $value ) {
        // Replace ]]> to avoid breaking CDATA
        $value = str_replace( ']]>', ']]]]><![CDATA[>', (string) $value );
        return '<![CDATA[' . $value . ']]>';
    }
}
