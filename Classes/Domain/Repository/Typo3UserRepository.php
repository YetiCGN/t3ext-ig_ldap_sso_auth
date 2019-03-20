<?php
/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Causal\IgLdapSsoAuth\Domain\Repository;

use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Causal\IgLdapSsoAuth\Exception\InvalidUserTableException;
use Causal\IgLdapSsoAuth\Library\Configuration;
use Causal\IgLdapSsoAuth\Utility\NotificationUtility;

/**
 * Class Typo3UserRepository for the 'ig_ldap_sso_auth' extension.
 *
 * @author     Xavier Perseguers <xavier@causal.ch>
 * @author     Michael Gagnon <mgagnon@infoglobe.ca>
 * @package    TYPO3
 * @subpackage ig_ldap_sso_auth
 */
class Typo3UserRepository
{

    /**
     * Creates a fresh BE/FE user record.
     *
     * @param string $table Either 'be_users' or 'fe_users'
     * @return array
     * @throws InvalidUserTableException
     */
    public static function create($table)
    {
        if (!GeneralUtility::inList('be_users,fe_users', $table)) {
            throw new InvalidUserTableException('Invalid table "' . $table . '"', 1404891582);
        }

        if (empty($GLOBALS['TCA'][$table])) {
            $bootstrap = \TYPO3\CMS\Core\Core\Bootstrap::getInstance();
            if (is_callable([$bootstrap, 'loadCachedTca'])) {
                $bootstrap->loadCachedTca();
            } else {
                ExtensionManagementUtility::loadBaseTca();
            }
        }

        $newUser = [];
        $fieldsConfiguration = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table)
            ->getSchemaManager()
            ->listTableColumns($table);

        foreach ($fieldsConfiguration as $field => $configuration) {
            $newUser[$field] = $configuration->getDefault();
            if (!empty($GLOBALS['TCA'][$table]['columns'][$field]['config']['default']) && $field !== 'disable') {
                $newUser[$field] = $GLOBALS['TCA'][$table]['columns'][$field]['config']['default'];
            }
        }

        // uid is a primary key, it should not be specified at all
        unset($newUser['uid']);

        return $newUser;
    }

    /**
     * Searches BE/FE users either by uid or by DN (or username)
     * in a given storage folder (pid).
     *
     * @param string $table Either 'be_users' or 'fe_users'
     * @param int $uid
     * @param int|null $pid
     * @param string $username
     * @param string $dn
     * @return array Array of user records
     * @throws InvalidUserTableException
     */
    public static function fetch(string $table, int $uid = 0, ?int $pid = null, ?string $username = null, ?string $dn = null): array
    {
        if (!GeneralUtility::inList('be_users,fe_users', $table)) {
            throw new InvalidUserTableException('Invalid table "' . $table . '"', 1404891636);
        }

        $users = [];

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        if (!empty($uid)) {
            // Search with uid
            $users = $queryBuilder
                ->select('*')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT))
                )
                ->execute()
                ->fetchAll();
        } elseif (!empty($dn)) {
            // Search with DN (or fall back to username) and pid
            $where = $queryBuilder->expr()->eq('tx_igldapssoauth_dn', $queryBuilder->createNamedParameter($dn, \PDO::PARAM_STR));
            if (!empty($username)) {
                // This additional condition will automatically add the mapping between
                // a local user unrelated to LDAP and a corresponding LDAP user
                $where = $queryBuilder->expr()->orX(
                    $where,
                    $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username, \PDO::PARAM_STR))
                );
            }
            if (!empty($pid)) {
                $where = $queryBuilder->expr()->andX(
                    $where,
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, \PDO::PARAM_INT))
                );
            }

            $users = $queryBuilder
                ->select('*')
                ->from($table)
                ->where($where)
                ->orderBy('tx_igldapssoauth_dn', 'DESC')    // rows from LDAP first...
                ->addOrderBy('deleted', 'ASC')              // ... then privilege active records
                ->execute()
                ->fetchAll();
        } elseif (!empty($username)) {
            // Search with username and pid
            $where = $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username, \PDO::PARAM_STR));
            if (!empty($pid)) {
                $where = $queryBuilder->expr()->andX(
                    $where,
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, \PDO::PARAM_INT))
                );
            }
            $users = $queryBuilder
                ->select('*')
                ->from($table)
                ->where($where)
                ->execute()
                ->fetchAll();
        }

        // Return TYPO3 users
        return $users;
    }

    /**
     * Adds a new BE/FE user to the database and returns the new record
     * with all columns.
     *
     * @param string $table Either 'be_users' or 'fe_users'
     * @param array $data
     * @return array The new record
     * @throws InvalidUserTableException
     */
    public static function add($table, array $data = [])
    {
        if (!GeneralUtility::inList('be_users,fe_users', $table)) {
            throw new InvalidUserTableException('Invalid table "' . $table . '"', 1404891712);
        }

        $tableConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table);

        $tableConnection->insert(
            $table,
            $data
        );

        $uid = $tableConnection->lastInsertId();

        $newRow = $tableConnection
            ->select(
                ['*'],
                $table,
                [
                    'uid' => $uid,
                ]
            )
            ->fetch();

        NotificationUtility::dispatch(
            __CLASS__,
            'userAdded',
            [
                'table' => $table,
                'user' => $newRow,
            ]
        );

        return $newRow;
    }

    /**
     * Updates a BE/FE user in the database and returns a success flag.
     *
     * @param string $table Either 'be_users' or 'fe_users'
     * @param array $data
     * @return bool true on success, otherwise false
     * @throws InvalidUserTableException
     */
    public static function update($table, array $data = [])
    {
        if (!GeneralUtility::inList('be_users,fe_users', $table)) {
            throw new InvalidUserTableException('Invalid table "' . $table . '"', 1404891732);
        }

        $cleanData = $data;
        unset($cleanData['__extraData']);

        $affectedRows = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table)
            ->update(
                $table,
                $cleanData,
                [
                    'uid' => (int)$data['uid'],
                ]
            );

        $success = $affectedRows === 1;

        if ($success) {
            NotificationUtility::dispatch(
                __CLASS__,
                'userUpdated',
                [
                    'table' => $table,
                    'user' => $data,
                ]
            );
        }

        return $success;
    }

    /**
     * Disables all users for a given LDAP configuration.
     *
     * This method is meant to be called before a full synchronization, so that existing users which are not
     * updated will be marked as disabled.
     *
     * @param $table
     * @param $uid
     */
    public static function disableForConfiguration($table, $uid)
    {
        if (isset($GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'])) {
            $fields = [
                $GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'] => 1
            ];
            if (isset($GLOBALS['TCA'][$table]['ctrl']['tstamp'])) {
                $fields[$GLOBALS['TCA'][$table]['ctrl']['tstamp']] = $GLOBALS['EXEC_TIME'];
            }
            static::getDatabaseConnection()->exec_UPDATEquery(
                $table,
                'tx_igldapssoauth_id = ' . (int)$uid,
                $fields
            );

            NotificationUtility::dispatch(
                __CLASS__,
                'userDisabled',
                [
                    'table' => $table,
                    'configuration' => $uid,
                ]
            );
        }
    }

    /**
     * Deletes all users for a given LDAP configuration.
     *
     * This method is meant to be called before a full synchronization, so that existing users which are not
     * updated will be marked as deleted.
     *
     * @param $table
     * @param $uid
     */
    public static function deleteForConfiguration($table, $uid)
    {
        if (isset($GLOBALS['TCA'][$table]['ctrl']['delete'])) {
            $fields = [
                $GLOBALS['TCA'][$table]['ctrl']['delete'] => 1
            ];
            if (isset($GLOBALS['TCA'][$table]['ctrl']['tstamp'])) {
                $fields[$GLOBALS['TCA'][$table]['ctrl']['tstamp']] = $GLOBALS['EXEC_TIME'];
            }
            static::getDatabaseConnection()->exec_UPDATEquery(
                $table,
                'tx_igldapssoauth_id = ' . (int)$uid,
                $fields
            );

            NotificationUtility::dispatch(
                __CLASS__,
                'userDeleted',
                [
                    'table' => $table,
                    'configuration' => $uid,
                ]
            );
        }
    }

    /**
     * Sets the user groups for a given TYPO3 user.
     *
     * @param array $typo3User
     * @param array $typo3Groups
     * @param string $table The TYPO3 table holding the user groups
     * @return array
     */
    public static function setUserGroups(array $typo3User, array $typo3Groups, $table)
    {
        $groupUid = [];

        foreach ($typo3Groups as $typo3Group) {
            if ($typo3Group['uid']) {
                $groupUid[] = $typo3Group['uid'];
            }
        }

        /** @var \TYPO3\CMS\Extbase\Domain\Model\BackendUserGroup[]|\TYPO3\CMS\Extbase\Domain\Model\FrontendUserGroup[] $assignGroups */
        $assignGroups = Configuration::getValue('assignGroups');
        foreach ($assignGroups as $group) {
            if (!in_array($group->getUid(), $groupUid)) {
                $groupUid[] = $group->getUid();
            }
        }

        if (Configuration::getValue('keepTYPO3Groups') && $typo3User['usergroup']) {
            $usergroup = GeneralUtility::intExplode(',', $typo3User['usergroup'], true);
            $localUserGroups = [];
            if (!empty($usergroup)) {
                $database = static::getDatabaseConnection();
                $rows = $database->exec_SELECTgetRows(
                    'uid',
                    $table,
                    'uid IN (' . implode(',', $usergroup) . ') AND tx_igldapssoauth_dn=' . $database->fullQuoteStr('', $table),
                    '',
                    '',
                    '',
                    'uid'
                );
                $localUserGroups = array_keys($rows);
            }

            foreach ($localUserGroups as $uid) {
                if (!in_array($uid, $groupUid)) {
                    $groupUid[] = $uid;
                }
            }
        }

        /** @var \TYPO3\CMS\Extbase\Domain\Model\BackendUserGroup[]|\TYPO3\CMS\Extbase\Domain\Model\FrontendUserGroup[] $administratorGroups */
        $administratorGroups = Configuration::getValue('updateAdminAttribForGroups');
        if (count($administratorGroups) > 0) {
            $typo3User['admin'] = 0;
            foreach ($administratorGroups as $administratorGroup) {
                if (in_array($administratorGroup->getUid(), $groupUid)) {
                    $typo3User['admin'] = 1;
                    break;
                }
            }
        }

        $typo3User['usergroup'] = implode(',', $groupUid);

        return $typo3User;
    }

    /**
     * Processes the username according to current configuration.
     *
     * @param string $username
     * @return string
     */
    public static function setUsername($username)
    {
        if (Configuration::getValue('forceLowerCaseUsername')) {
            // Possible enhancement: use \TYPO3\CMS\Core\Charset\CharsetConverter::conv_case instead
            $username = strtolower($username);
        }
        return $username;
    }

    /**
     * Defines a random password.
     *
     * @return string
     */
    public static function setRandomPassword()
    {
        /** @var \TYPO3\CMS\Saltedpasswords\Salt\SaltInterface $instance */
        $instance = null;
        if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('saltedpasswords')) {
            $instance = \TYPO3\CMS\Saltedpasswords\Salt\SaltFactory::getSaltingInstance(null, TYPO3_MODE);
        }
        $password = GeneralUtility::makeInstance(Random::class)->generateRandomBytes(16);
        $password = $instance ? $instance->getHashedPassword($password) : md5($password);
        return $password;
    }

    /**
     * Returns the database connection.
     *
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected static function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }

}
