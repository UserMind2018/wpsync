<?php
declare(strict_types=1);

namespace WpSync\Tests;

use PHPUnit\Framework\TestCase;
use WpSync\Canon;

final class CanonTest extends TestCase
{
    /** Spec §6.1: N für NULL, sonst S<Bytezahl>:<Bytes> */
    public function testValueEncoding(): void
    {
        $this->assertSame('N', Canon::value(null));
        $this->assertSame('S0:', Canon::value(''));
        $this->assertSame('S3:abc', Canon::value('abc'));
        $this->assertSame('S2:' . "\xC3\xA4", Canon::value('ä'), 'Bytes, nicht Zeichen');
    }

    public function testNullAndEmptyDiffer(): void
    {
        $this->assertNotSame(Canon::columns(['a'], ['a' => null]), Canon::columns(['a'], ['a' => '']));
    }

    public function testColumnsKeepTheGivenOrderAndIgnoreOthers(): void
    {
        $body = Canon::columns(['b', 'a'], ['a' => '1', 'b' => null, 'guid' => 'x']);
        $this->assertSame("b=N\na=S1:1\n", $body);
    }

    /** Meta als sortierte Multimenge: Reihenfolge egal, Duplikate zählen */
    public function testSetIsASortedMultiset(): void
    {
        $this->assertSame(Canon::set(['b', 'a', 'a']), Canon::set(['a', 'b', 'a']));
        $this->assertNotSame(Canon::set(['a', 'b']), Canon::set(['a', 'b', 'a']));
        $this->assertSame("S1:a\nS1:a\nS1:b\n", Canon::set(['b', 'a', 'a']));
        $this->assertSame("N\nS0:\n", Canon::set(['', null]));
    }

    public function testHashBindsTableAndKey(): void
    {
        $body = Canon::columns(Canon::TERMS, ['name' => 'Menü', 'slug' => 'menue', 'term_group' => '0']);
        $this->assertSame(hash('sha256', "terms\n7\nname=S5:Menü\nslug=S5:menue\nterm_group=S1:0\n"), Canon::hash('terms', '7', $body));
        $this->assertNotSame(Canon::hash('terms', '7', $body), Canon::hash('terms', '8', $body));
        $this->assertNotSame(Canon::hash('terms', '7', $body), Canon::hash('options', '7', $body));
    }

    public function testPairKeyAndTableLists(): void
    {
        $this->assertSame("219\0_elementor_data", Canon::pairKey('219', '_elementor_data'));
        $this->assertSame(['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'options'], Canon::TABLES);
        $this->assertCount(15, Canon::POSTS);
        foreach (['post_author', 'post_modified', 'post_modified_gmt', 'guid', 'to_ping', 'pinged', 'comment_count'] as $never) {
            $this->assertNotContains($never, Canon::POSTS);
        }
        $this->assertNotContains('count', Canon::TAXONOMY);
        $this->assertSame(1, Canon::VERSION);
    }
}
