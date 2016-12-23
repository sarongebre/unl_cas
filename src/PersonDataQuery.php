<?php

namespace Drupal\unl_cas;
use Symfony\Component\Ldap\Adapter\ExtLdap\Adapter;
use Symfony\Component\Ldap\Ldap;

/**
 * Configures unl_cas settings for this site.
 */
class PersonDataQuery
{
  const SOURCE_LDAP = 'ldap';
  const SOURCE_DIRECTORY = 'directory.unl.edu';
  
  function __construct() {
    //nothing to do here
  }
  
  public function getUserData($username) {
    $result = false;
    
    // First, try getting the info from LDAP.
    try {
      $client = $this->getClient();
      $query = $client->query('dc=unl,dc=edu', 'uid=' . $client->escape($username));
      $results = $query->execute();
      if (count($results) > 0) {
        $result = $results[0]->getAttributes();
        $result['data-source'] = self::SOURCE_LDAP;
      }
    }
    catch (\Exception $e) {
      // don't do anything, just go on to try the PeopleFinder method
    }

    // Next, if LDAP didn't work, try PeopleFinder service.
    if (!$result) {
      $json = file_get_contents('https://directory.unl.edu/service.php?format=json&uid=' . $username);
      if ($json) {
        $result = json_decode($json, TRUE);
        $result['data-source'] = self::SOURCE_DIRECTORY;
      }
    }
    
    if ($result) {
      return $this->sanitizeUserRecordData($result);
    }

    //Return the false value
    return $result;
  }

  /**
   * @return Ldap
   * @throws \Exception
   */
  protected function getClient() {
    static $client;
    
    if ($client !== null) {
      return $client;
    }
    
    $config = \Drupal::config('unl_cas.settings');
    
    if (empty($config->get('dn'))) {
      throw new \Exception('the LDAP DN is not set, we will be unable to connect to LDAP');
    }

    if (empty($config->get('password'))) {
      throw new \Exception('the LDAP password is not set, we will be unable to connect to LDAP');
    }

    if (empty($config->get('uri'))) {
      throw new \Exception('the LDAP uri is not set, we will be unable to connect to LDAP');
    }
    
    $adapter = new Adapter([
      'connection_string' => $config->get('uri'),
      'version' => 3,
    ]);
    $client = new Ldap($adapter);
    $client->bind($config->get('dn'), $config->get('password'));
    
    return $client;
  }

  /**
   * Sanitize a user record data that was retrieved from either LDAP or directory
   * 
   * @param array $data
   *
   * @return array
   */
  public function sanitizeUserRecordData(array $data) {
    $userData = [
      'uid'     => '',
      'mail'     => '',
      'data'     => [
        'unl' => [
          'fullName'           => '',
          'affiliations'       => '',
          'primaryAffiliation' => '',
          'department'         => '',
          'major'              => '',
          'studentStatus'      => [],
          'source'             => '',
        ]
      ],
    ];

    // If either LDAP or PeopleFinder found data, use it.
    if (!empty($data)) {
      $result = array_change_key_case($data, CASE_LOWER);
      
      $userData['data']['unl'] = [
        'fullName'           => (isset($result['edupersonnickname']) ? $result['edupersonnickname'][0] : $result['givenname'][0]) . ' ' . $result['sn'][0],
        'affiliations'       => $result['edupersonaffiliation'],
        'primaryAffiliation' => $result['edupersonprimaryaffiliation'][0],
        'department'         => (isset($result['unlhrprimarydepartment']) ? $result['unlhrprimarydepartment'][0] : ''),
        'major'              => (isset($result['unlsismajor']) ? $result['unlsismajor'][0] : ''),
        'studentStatus'      => (isset($result['unlsisstudentstatus']) ? $result['unlsisstudentstatus'] : []),
        'source'             => $data['data-source'],
      ];
      
      if (is_array($data['uid'])) {
        //ldap DOES return an array
        $userData['uid'] = $result['uid'][0];
      } else {
        //directory does not return an array
        $userData['uid'] = $result['uid'];
      }

      if ($result['mail'][0]) {
        $userData['mail'] = $result['mail'][0];
      } else {
        //No email found, use a default one
        $userData['mail'] = $userData['uid'].'@unl.edu';
      }
    }

    return $userData;
  }
}
