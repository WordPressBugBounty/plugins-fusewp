<?php

namespace FuseWPVendor\Carbon\Doctrine;

use FuseWPVendor\Carbon\Carbon;
use FuseWPVendor\Doctrine\DBAL\Types\VarDateTimeType;
class DateTimeType extends VarDateTimeType implements CarbonDoctrineType
{
    /** @use CarbonTypeConverter<Carbon> */
    use CarbonTypeConverter;
}
