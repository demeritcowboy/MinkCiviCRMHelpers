<?php
namespace Drupal\Tests\mink_civicrm_helpers\Traits;

/**
 * Trait containing some useful helper functions for running Mink tests with CiviCRM
 */
trait Utils {

  protected function setUpExtension(string $key): void {
    /**
     * Not much point to these tests if our extension isn't installed!
     * But you need to have set the path to the extensions dir where you're
     * developing this extension, since it's expecting everything under
     * the simpletest directory, but that doesn't exist yet until the tests
     * start.
     * Set it either in phpunit.mink.xml with <env name="DEV_EXTENSION_DIR" value="path_to_ext_folder"/>
     * or as an environment variable if not using phpunit.mink.xml
     */
    if ($extdir = getenv('DEV_EXTENSION_DIR')) {
      \Civi::settings()->set('extensionsDir', $extdir);
    }
    if ($exturl = getenv('DEV_EXTENSION_URL')) {
      \Civi::settings()->set('extensionsURL', $exturl);
    }
    if ($extdir || $exturl) {
      // Is there a better way to reset the extension system?
      \CRM_Core_Config::singleton(TRUE, TRUE);
      \CRM_Extension_System::setSingleton(new \CRM_Extension_System());
    }

    require_once 'api/api.php';
    civicrm_api3('Extension', 'install', ['keys' => $key]);
    // Drupal 8 is super cache-y.
    drupal_flush_all_caches();

    // Need this otherwise any new permissions aren't available yet.
    unset(\Civi::$statics['CRM_Core_Permission']['basicPermissions']);

    $this->configureCiviSettings();
  }

  /**
   * Miscellaneous civi settings that make it harder for errors to go unseen.
   *
   * You can either override this function in your test if you don't want
   * anything it does, or extend it using trait-renaming. For the latter, e.g.
   * in your `use` statement you would do:
   * use \Drupal\Tests\mink_civicrm_helpers\Traits\Utils {
   *   Utils::configureCiviSettings as utilsConfigureCiviSettings;
   * }
   * then in your override:
   * protected function configureCiviSettings(): void {
   *   $this->utilsConfigureCiviSettings();
   *   // do more stuff
   * }
   */
  protected function configureCiviSettings(): void {
    \Civi::settings()->add([
      // turn off the popup forms because ajax hides errors
      'ajaxPopupsEnabled' => 0,
      // display a backtrace on screen for exceptions
      'backtrace' => 1,
    ]);
  }

  /**
   * Asserts the page has no error messages.
   */
  protected function assertPageHasNoErrorMessages(): void {
    $error_messages = $this->getSession()->getPage()->findAll('css', '.messages.messages--error');
    $this->assertCount(0, $error_messages, implode(', ', array_map(static function(\Behat\Mink\Element\NodeElement $el) {
      return $el->getText();
    }, $error_messages)));

    // Check for fatal errors. Doing it this way so that the text itself which
    // contains the stack trace is automatically output to the github action
    // log.
    $the_text = $this->getSession()->getPage()->getText();
    $this->assertStringNotContainsString('The website encountered an unexpected error', $the_text);

    // Check civi status messages
    // This would be a more robust way but it doesn't work here because once the
    // message is displayed it's removed from the array, so we're too late.
    //
    // $session_messages = array_filter(\CRM_Core_Session::singleton()->getStatus(), function($x) {
    //   return ($x['type'] === 'error');
    // });
    // $this->assertEmpty($session_messages);

    // This does almost the same thing but using the UI. This might be a little
    // more comprehensive because some of these are generated purely from
    // javascript, but is tied to the css currently used by the popup.
    $civi_popups = $this->getSession()->getPage()->find('css', '.error.ui-notify-message');
    $this->assertNull($civi_popups, empty($civi_popups) ? '' : ('civi popup: ' . $civi_popups->getText()));
  }

  /**
   * Confusingly this is not BROWSER_OUTPUT_DIRECTORY but seems to be hardcoded.
   * You can use this if you want your test to create a file that gets uploaded
   * as a viewable artifact at the end of the tests. Put it in this folder.
   * @return string
   */
  protected function getBrowserOutputDirectory(): string {
    return DRUPAL_ROOT . '/sites/simpletest/browser_output/';
  }

  /**
   * Create a contact.
   * The civi core test framework includes a function called
   * individualCreate(). While it is technically possible to get access to it
   * in this environment, it's mostly a fluke and there's no guarantee that
   * will continue. This function is a replacement for it. Alternatively,
   * call the api directly yourself.
   *
   * Example: createContact(0, ['middle_name' => 'Apple']) would create a
   * contact named "Anthony Apple Anderson", whereas
   * createContact(1, ['first_name' => 'Jane']) would create a contact
   * "Jane Miller".
   *
   * @param int $index There are a couple stock contacts. You can pick a
   *   different index to get some different names etc to use as the base.
   * @param array $params Some more params to merge into the base.
   * @return array
   *   An api3 result array - the contact data.
   */
  protected function createContact(int $index = 0, array $params = []): array {
    $stockContacts = [
      'first_name' => ['Anthony', 'Joe', 'Terrence', 'Lucie', 'Albert', 'Bill', 'Kim'],
      'last_name' => ['Anderson', 'Miller', 'Smith', 'Collins', 'Peterson', 'Johnson', 'Li'],
    ];
    $vars = array_merge([
      'contact_type' => 'Individual',
      'first_name' => $stockContacts['first_name'][$index],
      'last_name' => $stockContacts['last_name'][$index],
      'email' => strtolower("{$stockContacts['first_name'][$index]}.{$stockContacts['last_name'][$index]}@example.org"),
      'phone' => preg_replace('/[^0-9]/', '', bin2hex("{$stockContacts['first_name'][$index]}{$stockContacts['last_name'][$index]}")),
    ], $params);

    // Phone doesn't work the same as email for create.
    // If the input params didn't blank it out, convert to right format.
    if (!empty($vars['phone'])) {
      $vars['api.Phone.create'] = ['phone' => $vars['phone']];
      unset($vars['phone']);
    }

    return civicrm_api3('Contact', 'create', $vars);
  }

  /**
   * Override pressButton to deal with new chrome issues. Stolen from
   * https://git.drupalcode.org/project/lms/-/merge_requests/82/diffs#e8d889b2b6302fed089d688dcd74ff2f907afdd8_668_673
   * See https://www.drupal.org/project/drupal/issues/3471113
   */
  protected function pressButtonOverride(string $selector, string $type = 'default'): void {
    $session = $this->getSession();
    $page = $session->getPage();
    $before = $page->getHtml();
    if ($type === 'default') {
      $button = $page->findButton($selector);
    }
    else {
      $button = $page->find($type, $selector);
    }
    $this->assertNotNull($button, \sprintf('Button "%s" not found.', $selector));
    $button->press();
    $result = $page->waitFor(5, function (\Behat\Mink\Element\DocumentElement $page) use ($before, $session) {
      $page_html = $page->getHtml();
      return $page_html !== '' && \strcmp($page_html, $before) !== 0 && (bool) $session->evaluateScript('document.readyState === "complete"');
    });
    $this->assertTrue($result, \sprintf("Pressing of the %s button didn't produce any results or page wasn't properly loaded afterwards.", $selector));
  }

}
