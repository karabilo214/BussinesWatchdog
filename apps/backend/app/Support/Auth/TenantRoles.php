<?php

namespace App\Support\Auth;

class TenantRoles
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const OPERATOR = 'operator';

    public const VIEWER = 'viewer';

    /**
     * @return list<string>
     */
    public static function storeRead(): array
    {
        return [
            self::OWNER,
            self::ADMIN,
            self::OPERATOR,
            self::VIEWER,
        ];
    }

    /**
     * @return list<string>
     */
    public static function storeManage(): array
    {
        return [
            self::OWNER,
            self::ADMIN,
        ];
    }

    /**
     * @return list<string>
     */
    public static function integrationRead(): array
    {
        return self::storeRead();
    }

    /**
     * @return list<string>
     */
    public static function integrationManage(): array
    {
        return self::storeManage();
    }

    /**
     * @return list<string>
     */
    public static function allocationManage(): array
    {
        return self::storeManage();
    }

    /**
     * @return list<string>
     */
    public static function reconciliationTrigger(): array
    {
        return [
            self::OWNER,
            self::ADMIN,
            self::OPERATOR,
        ];
    }

    /**
     * @return list<string>
     */
    public static function findingsRead(): array
    {
        return self::storeRead();
    }

    /**
     * @return list<string>
     */
    public static function incidentRead(): array
    {
        return self::storeRead();
    }

    /**
     * @return list<string>
     */
    public static function incidentManage(): array
    {
        return self::reconciliationTrigger();
    }

    /**
     * @return list<string>
     */
    public static function incidentSnooze(): array
    {
        return self::storeManage();
    }

    /**
     * @return list<string>
     */
    public static function teamManage(): array
    {
        return self::storeManage();
    }

    /**
     * @return list<string>
     */
    public static function notificationManage(): array
    {
        return self::storeManage();
    }

    /**
     * @return list<string>
     */
    public static function notificationDeliveryRead(): array
    {
        return self::storeRead();
    }
}
