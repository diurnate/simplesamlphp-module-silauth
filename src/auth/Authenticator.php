<?php
namespace Sil\SilAuth\auth;

use Psr\Log\LoggerInterface;
use Sil\SilAuth\auth\AuthError;
use Sil\SilAuth\time\WaitTime;
use Sil\SilAuth\models\User;

/**
 * An immutable class for making a single attempt to authenticate using a given
 * username and password.
 */
class Authenticator
{
    const REQUIRE_CAPTCHA_AFTER_NTH_FAILED_LOGIN = 2;
    const BLOCK_AFTER_NTH_FAILED_LOGIN = 3;
    const MAX_SECONDS_TO_BLOCK = 3600; // 3600 seconds = 1 hour
    
    /** @var AuthError|null */
    private $authError = null;
    private $userAttributes = null;
    
    /**
     * Attempt to authenticate using the given username and password. Check
     * isAuthenticated() to see whether authentication was successful.
     * 
     * @param string $username The username to check.
     * @param string $password The password to check.
     * @param Ldap $ldap An object for interacting with the LDAP server.
     * @param LoggerInterface $logger A PSR-3 compliant logger.
     */
    public function __construct($username, $password, $ldap, $logger)
    {
        if (empty($username)) {
            $this->setErrorUsernameRequired();
            return;
        }
        
        if (empty($password)) {
            $this->setErrorPasswordRequired();
            return;
        }
        
        $user = User::findByUsername($username);
        if ($user === null) {
            $this->avoidNonExistentUserTimingAttack($password);
            $this->setErrorInvalidLogin();
            return;
        }
        $user->setLogger($logger);
        
        if ($user->isBlockedByRateLimit()) {
            $this->setErrorBlockedByRateLimit(
                $user->getWaitTimeUntilUnblocked()
            );
            return;
        }
        
        if ($user->isLocked() || !$user->isActive()) {
            $this->setErrorInvalidLogin();
            return;
        }
        
        if ( ! $user->hasPasswordInDatabase()) {
            if ( ! $this->canConnectToLdap($ldap, $logger)) {
                $this->setErrorNeedToSetAcctPassword();
                return;
            }
            
            if ($ldap->isPasswordCorrectForUser($username, $password)) {
                $user->setPassword($password);
                
                /* Try to save the password, but let the user proceed even if
                 * we can't (since we know the password is correct).  */
                $user->tryToSave(sprintf(
                    'Failed to record password from LDAP into database for %s',
                    var_export($username, true)
                ));
            }
        }
        
        if ( ! $user->isPasswordCorrect($password)) {
            $user->recordLoginAttemptInDatabase();
            
            $user->refresh();
            if ($user->isBlockedByRateLimit()) {
                $this->setErrorBlockedByRateLimit(
                    $user->getWaitTimeUntilUnblocked()
                );
            } else {
                $this->setErrorInvalidLogin();
            }
            return;
        }
        
        // NOTE: If we reach this point, the user successfully authenticated.
        
        $user->resetFailedLoginAttemptsInDatabase();
        
        if ($user->isPasswordRehashNeeded()) {
            $user->tryToSaveRehashedPassword($password);
        }
        
        $this->setUserAttributes([
            'eduPersonTargetID' => [$user->uuid],
            'sn' => [$user->last_name],
            'givenName' => [$user->first_name],
            'mail' => [$user->email],
            'username' => [$user->username],
            'employeeId' => [$user->employee_id],
        ]);
    }
    
    /**
     * "Check" the given password against a dummy use to avoid exposing the
     * existence of certain users (or absence thereof) through a timing attack.
     * Technically, they could still deduce it since we don't rate-limit
     * non-existent accounts (in order to protect our database from a DDoS
     * attack), but this at least reduces the number of available side
     * channels.
     *
     * @param string $password
     */
    protected function avoidNonExistentUserTimingAttack($password)
    {
        $dummyUser = new User();
        $dummyUser->isPasswordCorrect($password);
    }
    
    public static function calculateSecondsToDelay($numRecentFailures)
    {
        if ( ! self::isEnoughFailedLoginsToBlock($numRecentFailures)) {
            return 0;
        }
        
        return min(
            $numRecentFailures * $numRecentFailures,
            self::MAX_SECONDS_TO_BLOCK
        );
    }
    
    /**
     * Get the error information (if any).
     * 
     * @return AuthError|null
     */
    public function getAuthError()
    {
        return $this->authError;
    }
    
    /**
     * Get the number of seconds to continue blocking, based on the given number
     * of recent failures and the given date/time of the most recent failed
     * login attempt.
     *
     * @param int $numRecentFailures The number of recent failed login attempts.
     * @param string|null $mostRecentFailureAt A date/time string for when the
     *     most recent failed login attempt occurred. If null (meaning there
     *     have been no recent failures), then zero (0) will be returned.
     * @return int The number of seconds
     * @throws Exception If an invalid (but non-null) date/time string is given
     *     for `$mostRecentFailureAt`.
     */
    public static function getSecondsUntilUnblocked(
        int $numRecentFailures,
        $mostRecentFailureAt
    ) {
        if ($mostRecentFailureAt === null) {
            return 0;
        }
        
        $totalSecondsToBlock = Authenticator::calculateSecondsToDelay(
            $numRecentFailures
        );
        
        $secondsSinceLastFailure = UtcTime::getSecondsSinceDateTime(
            $mostRecentFailureAt
        );
        
        return UtcTime::getRemainingSeconds(
            $totalSecondsToBlock,
            $secondsSinceLastFailure
        );
    }
    
    /**
     * Get the attributes about the authenticated user.
     *
     * @return array<string,array> The user attributes. Example:<pre>
     *     [
     *         // ...
     *         'mail' => ['someone@example.com'],
     *         // ...
     *     ]
     *     </pre>
     * @throws \Exception
     */
    public function getUserAttributes()
    {
        if ($this->userAttributes === null) {
            throw new \Exception(
                "You cannot get the user's attributes until you have authenticated the user.",
                1482270373
            );
        }
        
        return $this->userAttributes;
    }
    
    protected function hasError()
    {
        return ($this->authError !== null);
    }
    
    /**
     * Check whether authentication was successful. If not, call
     * getErrorMessage() and/or getErrorCode() to find out why not.
     * 
     * @return bool
     */
    public function isAuthenticated()
    {
        return ( ! $this->hasError());
    }
    
    protected function isCaptchaRequired($username, array $ipAddresses)
    {
        return FailedLoginUsername::isCaptchaRequiredFor($username) ||
               FailedLoginIpAddress::isCaptchaRequiredForAnyOfThese($ipAddresses);
    }
    
    public static function isEnoughFailedLoginsToBlock($numFailedLogins)
    {
        return ($numFailedLogins >= self::BLOCK_AFTER_NTH_FAILED_LOGIN);
    }
    
    public static function isEnoughFailedLoginsToRequireCaptcha($numFailedLogins)
    {
        return ($numFailedLogins >= self::REQUIRE_CAPTCHA_AFTER_NTH_FAILED_LOGIN);
    }
    
    protected function recordFailedLoginBy($username, array $ipAddresses)
    {
        FailedLoginUsername::recordFailedLoginBy($username, $this->logger);
        FailedLoginIpAddress::recordFailedLoginBy($ipAddresses, $this->logger);
    }
    
    protected function resetFailedLoginsBy($username, array $ipAddresses)
    {
        FailedLoginUsername::resetFailedLoginsBy($username);
        FailedLoginIpAddress::resetFailedLoginsBy($ipAddresses);
    }
    
    protected function setError($code, $messageParams = [])
    {
        $this->authError = new AuthError($code, $messageParams);
    }
    
    /**
     * @param WaitTime $waitTime
     */
    protected function setErrorBlockedByRateLimit($waitTime)
    {
        $unit = $waitTime->getUnit();
        $number = $waitTime->getFriendlyNumber();
        
        if ($unit === WaitTime::UNIT_SECOND) {
            $errorCode = AuthError::CODE_RATE_LIMIT_SECONDS;
        } else { // = minute
            if ($number === 1) {
                $errorCode = AuthError::CODE_RATE_LIMIT_1_MINUTE;
            } else {
                $errorCode = AuthError::CODE_RATE_LIMIT_MINUTES;
            }
        }
        
        $this->setError($errorCode, ['{number}' => $number]);
    }
    
    protected function setErrorGenericTryLater()
    {
        $this->setError(AuthError::CODE_GENERIC_TRY_LATER);
    }
    
    protected function setErrorInvalidLogin()
    {
        $this->setError(AuthError::CODE_INVALID_LOGIN);
    }
    
    protected function setErrorNeedToSetAcctPassword()
    {
        $this->setError(AuthError::CODE_NEED_TO_SET_ACCT_PASSWORD);
    }
    
    protected function setErrorPasswordRequired()
    {
        $this->setError(AuthError::CODE_PASSWORD_REQUIRED);
    }
    
    protected function setErrorUsernameRequired()
    {
        $this->setError(AuthError::CODE_USERNAME_REQUIRED);
    }
    
    protected function setUserAttributes($attributes)
    {
        $this->userAttributes = $attributes;
    }
}
