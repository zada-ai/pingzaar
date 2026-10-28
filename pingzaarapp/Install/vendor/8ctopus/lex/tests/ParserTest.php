<?php

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ParserTest extends TestCase
{
    private $parser;

    protected function setUp() : void
    {
        $this->parser = new Lex\Parser();
    }

    public function templateDataProvider()
    {
        return [
            [
                [
                    'name' => 'Lex',
                    'filters' => [
                        'enable' => true,
                    ],
                ],
            ],
        ];
    }

    public function testCanSetScopeGlue() : void
    {
        $this->parser->scopeGlue('~');
        $scopeGlue = new ReflectionProperty($this->parser, 'scopeGlue');

        static::assertTrue($scopeGlue->isProtected());

        $scopeGlue->setAccessible(true);
        static::assertSame('~', $scopeGlue->getValue($this->parser));
    }

    public function testCanGetScopeGlue() : void
    {
        $this->parser->scopeGlue('~');
        static::assertSame('~', $this->parser->scopeGlue());
    }

    public function testValueToLiteral() : void
    {
        $method = new ReflectionMethod($this->parser, 'valueToLiteral');

        static::assertTrue($method->isProtected());

        $method->setAccessible(true);

        static::assertSame('NULL', $method->invoke($this->parser, null));
        static::assertSame('true', $method->invoke($this->parser, true));
        static::assertSame('false', $method->invoke($this->parser, false));
        static::assertSame("'some_string'", $method->invoke($this->parser, 'some_string'));
        static::assertSame('24', $method->invoke($this->parser, 24));
        static::assertSame('true', $method->invoke($this->parser, ['foo']));
        static::assertSame('false', $method->invoke($this->parser, []));

        $version = explode('.', PHP_VERSION);
        $class = ((int) $version[0] >= 8) ? 'Stringable' : 'stdClass';

        $mock = $this->createPartialMock($class, ['__toString']);
        $mock->expects(static::any())
            ->method('__toString')
            ->willReturn('obj_string');

        static::assertSame("'obj_string'", $method->invoke($this->parser, $mock));
    }

    /**
     * @dataProvider templateDataProvider
     *
     * @param mixed $data
     */
    public function testGetVariable($data) : void
    {
        $method = new ReflectionMethod($this->parser, 'getVariable');

        static::assertTrue($method->isProtected());

        $method->setAccessible(true);

        static::assertSame('Lex', $method->invoke($this->parser, 'name', $data));
        static::assertNull($method->invoke($this->parser, 'age', $data));
        static::assertFalse($method->invoke($this->parser, 'age', $data, false));

        static::assertTrue($method->invoke($this->parser, 'filters.enable', $data));
        static::assertNull($method->invoke($this->parser, 'filters.name', $data));
        static::assertFalse($method->invoke($this->parser, 'filters.name', $data, false));
    }

    /**
     * Regression test for https://www.pyrocms.com/forums/topics/view/19686
     */
    public function testFalseyVariableValuesParseProperly() : void
    {
        $data = [
            'zero_num' => 0,
            'zero_string' => '0',
            'zero_float' => 0.0,
            'empty_string' => '',
            'null_value' => null,
            'simplexml_empty_node' => simplexml_load_string('<main></main>'),
        ];

        $text = '{{zero_num}},{{zero_string}},{{zero_float}},{{empty_string}},{{null_value}},{{simplexml_empty_node}}';
        $expected = '0,0,0,,,';

        $result = $this->parser->parseVariables($text, $data);

        static::assertSame($expected, $result);
    }

    /**
     * @dataProvider templateDataProvider
     *
     * @param mixed $data
     */
    public function testExists($data) : void
    {
        $result = $this->parser->parse('{{ if exists name }}1{{ else }}0{{ endif }}', $data);
        static::assertSame('1', $result);

        $result = $this->parser->parse('{{ if not exists age }}0{{ else }}1{{ endif }}', $data);
        static::assertSame('0', $result);
    }

    /**
     * Regression test for https://github.com/fuelphp/lex/issues/2
     *
     * @dataProvider templateDataProvider
     *
     * @param mixed $data
     */
    public function testUndefinedInConditional($data) : void
    {
        $result = $this->parser->parse('{{ if age }}0{{ else }}1{{ endif }}', $data);
        static::assertSame('1', $result);
    }

    /**
     * Regression test for https://github.com/pyrocms/pyrocms/issues/1906
     */
    public function testCallbacksInConditionalComparison() : void
    {
        $result = $this->parser->parse("{{ if foo.bar.baz == 'yes' }}Yes{{ else }}No{{ endif }}", [], function ($name, $attributes, $content) {
            if ($name === 'foo.bar.baz') {
                return 'yes';
            }
            return 'no';
        });
        static::assertSame('Yes', $result);
    }

    /**
     * Test for https://github.com/pyrocms/pyrocms/issues/2104
     *
     * Failing IF statements multiple levels deep
     * - IS_JUL       Tests 'text' == 'text'
     * - TOTAL_GT_0   Tests total > 0
     * - HAS_ENTRIES  Tests isset(entries)
     */
    public function testDeepCallbacksInConditionalComparison() : void
    {
        $data = [
            'pagination' => null,
            'total' => 172,
            'years' => [
                2012 => [
                    'year' => '2012',
                    'months' => [
                        '01' => [
                            'month' => 'jan',
                            'month_num' => '01',
                            'date' => 946713600,
                            'total' => 3,
                            'entries' => [
                                1326787200 => [],
                                1326355200 => [],
                                1325577600 => [],
                            ],
                        ],
                        '02' => [
                            'month' => 'feb',
                            'month_num' => '02',
                            'date' => 949392000,
                            'total' => 0,
                        ],
                        '07' => [
                            'month' => 'jul',
                            'month_num' => '07',
                            'date' => 962434800,
                            'total' => 1,
                            'entries' => [
                                1343026800 => [],
                            ],
                        ],
                        10 => [
                            'month' => 'oct',
                            'month_num' => '10',
                            'date' => 970383600,
                            'total' => 2,
                            'entries' => [
                                1350543600 => [],
                                1350457200 => [],
                            ],
                        ],
                        11 => [
                            'month' => 'nov',
                            'month_num' => '11',
                            'date' => 973065600,
                            'total' => 4,
                            'entries' => [
                                1354003200 => [],
                                1353398400 => [],
                                1352707200 => [],
                            ],
                        ],
                        12 => [
                            'month' => 'dec',
                            'month_num' => '12',
                            'date' => 975657600,
                            'total' => 0,
                        ],
                    ],
                ],
                2011 => [
                    'year' => '2011',
                    'months' => [
                        '01' => [
                            'month' => 'jan',
                            'month_num' => '01',
                            'date' => 946713600,
                            'total' => 0,
                        ],
                        '04' => [
                            'month' => 'apr',
                            'month_num' => '04',
                            'date' => 954576000,
                            'total' => 13,
                            'entries' => [
                                1303974000 => [],
                                1303887600 => [],
                            ],
                        ],
                        '07' => [
                            'month' => 'jul',
                            'month_num' => '07',
                            'date' => 962434800,
                            'total' => 0,
                        ],
                        '08' => [
                            'month' => 'aug',
                            'month_num' => '08',
                            'date' => 965113200,
                            'total' => 8,
                            'entries' => [
                                1313391600 => [],
                                1313046000 => [],
                                1312354800 => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $html = <<<'HTML'
YEARS(
{{ years }}
    YEAR: {{ year }}
    MONTHS:(
{{ months }}
        MONTH: {{ month }}, IS_JUL: {{ if month == 'jul' }}true{{ else }}false{{ endif }}, TOTAL_GT_0: {{ if total > 0 }}true{{ else }}false{{ endif }}, HAS_ENTRIES: {{ if entries }}true{{ else }}false{{ endif }}

{{ /months }}
    )
{{ /years }}
)
HTML;

        $expected_html = <<<'HTML'
YEARS(
    YEAR: 2012
    MONTHS:(
        MONTH: jan, IS_JUL: false, TOTAL_GT_0: true, HAS_ENTRIES: true
        MONTH: feb, IS_JUL: false, TOTAL_GT_0: false, HAS_ENTRIES: false
        MONTH: jul, IS_JUL: true, TOTAL_GT_0: true, HAS_ENTRIES: true
        MONTH: oct, IS_JUL: false, TOTAL_GT_0: true, HAS_ENTRIES: true
        MONTH: nov, IS_JUL: false, TOTAL_GT_0: true, HAS_ENTRIES: true
        MONTH: dec, IS_JUL: false, TOTAL_GT_0: false, HAS_ENTRIES: false

    )
    YEAR: 2011
    MONTHS:(
        MONTH: jan, IS_JUL: false, TOTAL_GT_0: false, HAS_ENTRIES: false
        MONTH: apr, IS_JUL: false, TOTAL_GT_0: true, HAS_ENTRIES: true
        MONTH: jul, IS_JUL: true, TOTAL_GT_0: false, HAS_ENTRIES: false
        MONTH: aug, IS_JUL: false, TOTAL_GT_0: true, HAS_ENTRIES: true

    )

)
HTML;

        $result = $this->parser->parse($html, $data);

        static::assertSame($expected_html, $result);
    }

    public function testSelfClosingTag() : void
    {
        $self = $this;
        $result = $this->parser->parse('{{ foo.bar.baz /}}Here{{ foo.bar.baz }}Content{{ /foo.bar.baz }}', [], function ($name, $attributes, $content) {
            if ($content === '') {
                return 'DanWas';
            } else {
                return '';
            }
        });
        static::assertSame('DanWasHere', $result);
    }

    /**
     * Test that the toArray method converts an standard object to an array
     */
    public function testObjectToArray() : void
    {
        $data = new stdClass();
        $data->foo = 'bar';

        $result = $this->parser->toArray($data);

        static::assertSame(['foo' => 'bar'], $result);
    }

    /**
     * Test that the toArray method converts an object that implements ArrayableInterface to an array
     */
    public function testArrayableInterfaceToArray() : void
    {
        $data = new Lex\ArrayableObjectExample();

        $result = $this->parser->toArray($data);

        static::assertSame(['foo' => 'bar'], $result);
    }

    /**
     * Test that the toArray method converts an integer to an array
     */
    public function testIntegerToArray() : void
    {
        $data = 1;

        $result = $this->parser->toArray($data);

        static::assertTrue(is_array($result));
    }

    /**
     * Test that the toArray method converts an string to an array
     */
    public function testStringToArray() : void
    {
        $data = 'Hello World';

        $result = $this->parser->toArray($data);

        static::assertTrue(is_array($result));
    }

    /**
     * Test that the toArray method converts an boolean to an array
     */
    public function testBooleanToArray() : void
    {
        $data = true;

        $result = $this->parser->toArray($data);

        static::assertTrue(is_array($result));
    }

    /**
     * Test that the toArray method converts an null value to an array
     */
    public function testNullToArray() : void
    {
        $data = null;

        $result = $this->parser->toArray($data);

        static::assertTrue(is_array($result));
    }

    /**
     * Test that the toArray method converts an float value to an array
     */
    public function testFloatToArray() : void
    {
        $data = 1.23456789;

        $result = $this->parser->toArray($data);

        static::assertTrue(is_array($result));
    }
}
