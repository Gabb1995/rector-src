<?php

namespace Rector\Tests\Php80\Rector\If_\NullsafeOperatorRector\Fixture;

final class FixtureDoNotReplaceService
{
    private $myService;

    public function run($prop1, $prop2)
    {
        $prop3 = $this->getProp3($prop1);

        if (null === $prop3) {
            return null;
        }

        return $this->myService->myMethod($prop1, $prop2, $prop3);
    }
}
?>
-----
<?php

namespace Rector\Tests\Php80\Rector\If_\NullsafeOperatorRector\Fixture;

final class FixtureDoNotReplaceService
{
    private $myService;

    public function run($prop1, $prop2)
    {
        $prop3 = $this->getProp3($prop1);

        if (null === $prop3) {
            return null;
        }

        return $this->myService->myMethod($prop1, $prop2, $prop3);
    }
}
?>
