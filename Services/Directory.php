<?php

namespace FL\GmailBundle\Services;

use FL\GmailBundle\Model\Collection\GmailDomain;
use FL\GmailBundle\Model\GmailUser;
use FL\GmailBundle\Model\GmailUserInterface;

/**
 * This class allows us to resolve userIds and emails in a domain,
 * by communicating with an instance of @see \Google_Service_Directory.
 *
 * This service exists for a single \GoogleClient
 *
 * @see ServiceAccount::getGoogleClientForAdmin()
 */
class Directory
{
    const MODE_RESOLVE_PRIMARY_ONLY = 0;
    const MODE_RESOLVE_ALIASES_ONLY = 1;
    const MODE_RESOLVE_PRIMARY_PLUS_ALIASES = 2;

    /**
     * @var \Google_Service_Directory
     */
    private $directory;

    /**
     * @var OAuth
     */
    private $oAuth;

    /**
     * @var GmailDomain
     */
    private $gmailDomain;

    /**
     * @param \Google_Service_Directory $directory
     * @param OAuth                     $oAuth
     */
    public function __construct(
        \Google_Service_Directory $directory,
        OAuth $oAuth
    ) {
        $this->directory = $directory;
        $this->oAuth = $oAuth;
    }

    /**
     * @param string $domain
     *
     * @return GmailDomain
     */
    private function resolveDomain(string $domain): GmailDomain
    {
        $nextPage = null;

        $gmailDomain = new GmailDomain($domain);

        do {
            $usersResponse = $this->directory->users->listUsers([
                'domain' => $gmailDomain->getDomain(),
                'pageToken' => $nextPage,
            ]);
            /** @var \Google_Service_Directory_User $user */
            foreach ($usersResponse->getUsers() as $user) {
                $primaryEmailAddress = $user->getPrimaryEmail();
                $gmailUser = new GmailUser();
                $gmailUser->setUserId($user->getId());
                $gmailUser->setPrimaryEmailAddress($primaryEmailAddress);
                foreach ($user->getEmails() as $email) { // primary email is in this list as well
                    if ($email['address'] !== $primaryEmailAddress) {
                        $gmailUser->addEmailAlias($email['address']);
                    }
                }
                $gmailDomain->addGmailUser($gmailUser);
            }
        } while (($nextPage = $usersResponse->getNextPageToken()));

        return $gmailDomain;
    }

    /**
     * @return GmailDomain
     */
    private function getGmailDomain()
    {
        if (!$this->gmailDomain) {
            $this->gmailDomain = $this->resolveDomain($this->oAuth->resolveDomain());
        }

        return $this->gmailDomain;
    }

    /**
     * @param string $userId
     * @param int    $mode
     *
     * @return string[] E.g.
     *                  [
     *                  0 => "test@example.com",
     *                  1 => "test_alias@example.com",
     *                  ]
     */
    public function resolveEmailsFromUserId(string $userId, int $mode)
    {
        $gmailUser = $this->getGmailDomain()->findGmailUserById($userId);

        if (!($gmailUser instanceof GmailUserInterface)) {
            return [];
        }

        switch ($mode) {
            case self::MODE_RESOLVE_PRIMARY_ONLY:
                return [$gmailUser->getPrimaryEmailAddress()];

            case self::MODE_RESOLVE_ALIASES_ONLY:
                return $gmailUser->getEmailAliases();

            case self::MODE_RESOLVE_PRIMARY_PLUS_ALIASES:
                return $gmailUser->getAllEmailAddresses();

            default:
                throw new \InvalidArgumentException();
        }
    }

    /**
     * @param string $userId
     *
     * @return null|string
     */
    public function resolvePrimaryEmailFromUserId(string $userId)
    {
        $gmailUser = $this->getGmailDomain()->findGmailUserById($userId);

        if (!($gmailUser instanceof GmailUserInterface)) {
            return;
        }

        return $gmailUser->getPrimaryEmailAddress();
    }

    /**
     * @param int $mode
     *
     * @return string[] E.g.
     *                  [
     *                  0 => "test@example.com",
     *                  1 => "test_alias@example.com",
     *                  2 => "test2@example.com"
     *                  ]
     */
    public function resolveEmails(int $mode)
    {
        $emails = [];

        /** @var GmailUserInterface $gmailUser */
        foreach ($this->getGmailDomain()->getGmailUsers() as $gmailUser) {
            $gmailUserEmails = $this->resolveEmailsFromUserId($gmailUser->getUserId(), $mode);
            foreach ($gmailUserEmails as $email) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * @param string $email
     * @param int    $mode
     *
     * @return string|null E.g. "12831283123123"
     */
    public function resolveUserIdFromEmail(string $email, int $mode)
    {
        switch ($mode) {
            case self::MODE_RESOLVE_PRIMARY_ONLY:
                $gmailUser = $this->getGmailDomain()->findGmailUserByPrimaryEmail($email);
                break;

            case self::MODE_RESOLVE_ALIASES_ONLY:
                $gmailUser = $this->getGmailDomain()->findGmailUserByEmailAlias($email);
                break;

            case self::MODE_RESOLVE_PRIMARY_PLUS_ALIASES:
                $gmailUser = $this->getGmailDomain()->findGmailUserByEmail($email);
                break;

            default:
                throw new \InvalidArgumentException();
        }

        return $gmailUser ? $gmailUser->getUserId() : null;
    }

    /**
     * @return string[] E.g.
     *                  [
     *                  0 => "12831283123123",
     *                  1 => "12831283123123",
     *                  2 => "1045618888777"
     *                  ]
     */
    public function resolveUserIds()
    {
        $userIds = [];

        /** @var $gmailUser GmailUserInterface */
        foreach ($this->getGmailDomain()->getGmailUsers() as $gmailUser) {
            $userIds[] = $gmailUser->getUserId();
        }

        return $userIds;
    }

    /**
     * @param string $separator
     * @param int    $mode
     *
     * @return string[] E.g. for ", " $separator
     *                  [
     *                  "12831283123123" => "test@example.com, test_alias@example.com",
     *                  "1045618888777" => "test2@example.com"
     *                  ]
     */
    public function resolveUserIdToInboxesArray(string $separator, int $mode)
    {
        $return = [];
        foreach ($this->resolveUserIds() as $userId) {
            $return[$userId] = implode($separator, $this->resolveEmailsFromUserId($userId, $mode));
        }

        return $return;
    }

    /**
     * @param string $separator
     * @param int    $mode
     *
     * @return string[] E.g. for ", " $separator
     *                  [
     *                  "test@example.com, test_alias@example.com" => "12831283123123",
     *                  "test2@example.com" => "1045618888777"
     *                  ]
     */
    public function resolveInboxesToUserIdArray(string $separator, int $mode)
    {
        return array_flip($this->resolveUserIdToInboxesArray($separator, $mode));
    }
}
