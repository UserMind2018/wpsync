<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Classifier;

final class ClassifierTest extends TestCase
{
    public function testCoreTables(): void
    {
        $this->assertSame(['class' => 'content', 'plugin' => 'core', 'essential' => true], Classifier::table('wp_posts', 'wp_'));
        $this->assertSame(['class' => 'config', 'plugin' => 'core', 'essential' => true], Classifier::table('wp_options', 'wp_'));
        $this->assertSame(['class' => 'pii', 'plugin' => 'core', 'essential' => true], Classifier::table('wp_users', 'wp_'));
        $this->assertSame(['class' => 'pii', 'plugin' => 'core', 'essential' => false], Classifier::table('wp_comments', 'wp_'));
    }

    /** AC-8: Formular-Einsendungen und Backup-Plugins aus realen Installationen. */
    public function testFormAndBackupTables(): void
    {
        $this->assertSame('pii', Classifier::table('wp_e_submissions_values', 'wp_')['class']);
        $this->assertSame('pii', Classifier::table('wp_e_submissions', 'wp_')['class']);
        $this->assertSame('backup', Classifier::table('wp_wptc_processed_files', 'wp_')['class']);
        $this->assertSame('backup', Classifier::table('wp_iwp_backup_status', 'wp_')['class']);
        $this->assertSame('backup', Classifier::table('wp_duplicator_pro_packages', 'wp_')['class']);
    }

    public function testLongestPrefixWins(): void
    {
        $this->assertSame('config', Classifier::table('wp_borlabs_cookie_content_blocker', 'wp_')['class']);
        $this->assertSame('pii', Classifier::table('wp_borlabs_cookie_consent_log', 'wp_')['class']);
        $this->assertSame('log', Classifier::table('wp_borlabs_cookie_consent_statistic_entries', 'wp_')['class']);
        $this->assertSame('log', Classifier::table('wp_rank_math_analytics_gsc', 'wp_')['class']);
        $this->assertSame('config', Classifier::table('wp_rank_math_redirections', 'wp_')['class']);
        $this->assertSame('pii', Classifier::table('wp_rg_lead_detail', 'wp_')['class']);
        $this->assertSame('config', Classifier::table('wp_rg_form', 'wp_')['class']);
    }

    public function testCaseInsensitiveAndCustomPrefix(): void
    {
        $this->assertSame('config', Classifier::table('um_wfConfig', 'um_')['class']);
        $this->assertSame('log', Classifier::table('um_wfHits', 'um_')['class']);
        $this->assertSame('pii', Classifier::table('um_wc_orders', 'um_')['class']);
        $this->assertSame('woocommerce', Classifier::table('um_wc_orders', 'um_')['plugin']);
        $this->assertSame('log', Classifier::table('um_actionscheduler_logs', 'um_')['class']);
    }

    public function testUnknownTable(): void
    {
        $this->assertSame(['class' => 'unknown', 'plugin' => null, 'essential' => false], Classifier::table('wp_my_custom_thing', 'wp_'));
    }

    /** AC-8: Revisionen und iwp_log als log, Bewerbungen als pii. */
    public function testPostTypes(): void
    {
        $this->assertSame('log', Classifier::postType('revision'));
        $this->assertSame('log', Classifier::postType('iwp_log'));
        $this->assertSame('pii', Classifier::postType('jobpost_applicants'));
        $this->assertSame('pii', Classifier::postType('shop_order'));
        $this->assertSame('config', Classifier::postType('nav_menu_item'));
        $this->assertSame('content', Classifier::postType('page'));
        $this->assertSame('content', Classifier::postType('some_cpt'));
    }

    public function testEveryRuleUsesAKnownClass(): void
    {
        foreach (Classifier::ruleClasses() as $class) {
            $this->assertContains($class, Classifier::CLASSES);
        }
    }
}
