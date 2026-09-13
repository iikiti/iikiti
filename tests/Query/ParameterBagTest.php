<?php

namespace iikiti\CMS\Tests\Query;

use iikiti\CMS\Query\Parameter;
use iikiti\CMS\Query\ParameterBag;
use PHPUnit\Framework\TestCase;

final class ParameterBagTest extends TestCase
{
	public function testAddAssignsSequentialNames(): void
	{
		$bag = new ParameterBag();

		$first = $bag->add(new Parameter('a'));
		$second = $bag->add(new Parameter('b'));

		$this->assertSame('qp1', $first->getName());
		$this->assertSame('qp2', $second->getName());
		$this->assertSame(':qp1', $first->getPlaceholder());
	}

	public function testAddKeepsExistingName(): void
	{
		$bag = new ParameterBag();
		$named = (new Parameter('a'))->setName('custom');

		$bag->add($named);

		$this->assertSame('custom', $named->getName());
		$this->assertTrue($bag->has('custom'));
	}

	public function testNamesAreDeterministicPerBag(): void
	{
		$first = new ParameterBag();
		$second = new ParameterBag();

		$this->assertSame('qp1', $first->add(new Parameter('a'))->getName());
		$this->assertSame('qp2', $first->add(new Parameter('b'))->getName());
		// A second bag starts from the same sequence, so identical queries
		// render identical SQL text.
		$this->assertSame('qp1', $second->add(new Parameter('x'))->getName());
		$this->assertSame('qp2', $second->add(new Parameter('y'))->getName());
	}

	public function testMergeRenamesCollidingParameters(): void
	{
		$first = new ParameterBag();
		$first->add(new Parameter('a'));

		$second = new ParameterBag();
		$incoming = $second->add(new Parameter('b'));

		$first->merge($second);

		$this->assertSame('qp2', $incoming->getName());
		$this->assertCount(2, $first->all());
		$this->assertSame('a', $first->get('qp1')?->getValue());
		$this->assertSame('b', $first->get('qp2')?->getValue());
		$this->assertFalse($first->isEmpty());
	}

	public function testImportRenamesCollidingParameter(): void
	{
		$bag = new ParameterBag();
		$bag->add(new Parameter('a'));

		$imported = $bag->import((new Parameter('b'))->setName('qp1'));

		$this->assertSame('qp2', $imported->getName());
	}

	public function testGetAndHas(): void
	{
		$bag = new ParameterBag();
		$parameter = $bag->add(new Parameter('a'));

		$this->assertTrue($bag->has('qp1'));
		$this->assertSame($parameter, $bag->get('qp1'));
		$this->assertNull($bag->get('missing'));
	}

	public function testEmptyBag(): void
	{
		$this->assertTrue((new ParameterBag())->isEmpty());
	}
}
