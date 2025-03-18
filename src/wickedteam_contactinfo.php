<?php

/**
 * Content Plugin.
 *
 * @package    Wickedteam_Contactinfo
 * @subpackage Plugin
 * @author     Heinl Christian <heinchrs@gmail.com>
 * @copyright  (C) 2013-2025 Heinl Christian
 * @license    GNU General Public License version 2 or later
 * @abstract   With this plugin it is possible to display WickedTeam field values at a specific position within content.
 *             Therefore a area is defined by the tags {wickedteamcontactinfo} {/wickedteamcontactinfo}. Within this area
 *             WickedTeam fieldnames (alias names) can be written enclosed by square brackets, which are replaced by the
 *             plugin with the corresponding WickedTeam field values.
 *             Example: {wickedteamcontactinfo id=1}[lastname] [firstname]{/wickedteamcontactinfo}
 *             The member selection can be done via id, or by a query.
 *             For the id selection the id has to be specified after the tag wickedteamcontactinfo via id=x, e.g. {wickedteamcontactinfo id=1}
 *             The selection by query is done via the WickedTeam alias value and its required content, separated by ':', e.g.
 *             {wickedteamcontactinfo query=lastname:Mustermann&firstname:Max}
 *             Some examples:
 *             {wickedteamcontactinfo id=1}[lastname] [firstname]{/wickedteamcontactinfo}
 *             {wickedteamcontactinfo query=lastname:Mustermann&firstname:Max}[lastname] [firstname]{/wickedteamcontactinfo}
 */



// -- No direct access
defined('_JEXEC') || die('=;)');


use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Factory;

/**
 * Content Plugin.
 *
 * @author  Heinl Christian <heinchrs@gmail.com>
 * @since 1.0
 */
class PlgContentWickedteam_Contactinfo extends CMSPlugin
{
	/**
	 * The application object
	 * @var JApplication
	 */
	protected $app;

	/**
	 * Constructor
	 *
	 * @param   object $subject The object to observe
	 * @param   array  $config  An optional associative array of configuration settings.
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);

		$this->app = Factory::getApplication();

		// Load language file for plugin frontend
		$this->loadLanguage();
	}

	/**
	 * Method is called when content is prepared for output
	 *
	 * Method is called by the view
	 *
	 * @param   string  $context     The context of the content being passed to the plugin.
	 * @param   object  $article     The content object.  Note $article->text is also available
	 * @param   object  $params      The content params
	 * @param   int     $limitstart  The 'page' number
	 * @return  boolean
	 */
	public function onContentPrepare($context, &$article, &$params, $limitstart)
	{
		// Don't run this plugin when it is not within article context
		if ($context != 'com_content.article' && $context != 'mod_custom.content')
		{
			return true;
		}

		// Regular expression to search if tags {wickedteamcontactinfo} ... {/wickedteamcontactinfo} are present
		$regex = "#{wickedteamcontactinfo\s*(.*?)\}(.*?){/wickedteamcontactinfo}#s";

		// If no valid {wickedteamContactinfoReplacer} tag combination is found -> return
		if (!preg_match($regex, $article->text))
		{
			// Match attempt failed
			return true;
		}

		// Replace {wickedteamContactinfoReplacer} tag combination by corresponding text
		// This is done by callback method 'wickedteamContactinfoReplacer'

		/**
		* Because of 'wickedteamContactinfoReplacer' is a non static class member,
		* it has to be called by array(&$this, 'wickedteamContactinfoReplacer')
		* which passes also the this pointer of the current instance.
		*/
		$article->text = preg_replace_callback($regex, array(&$this, 'wickedteamContactinfoReplacer'), $article->text);

		return true;
	}

	/**
	 * This method is used for examining the matched regular expression parts.
	 * Typical match strings can be:
	 *   {wickedteamcontactinfo id=1}[lastname] [firstname]{/wickedteamcontactinfo}
	 *   {wickedteamcontactinfo query=lastname:Mustermann&firstname:Max}[lastname] [firstname]{/wickedteamcontactinfo}
	 *
	 * The passed parameter $matches containes the folllowed values:
	 *   matches[0] contains the complete found regex
	 *   matches[1] contains the id/query for selecting data
	 *   matches[2] contains the values between the {wickedteamcontactinfo} tags
	 *
	 * When a query is used for selecting data in parameter matches[1], then first
	 * the corresponding wickedteam member_id is fetched by nesting SQL statements.
	 * After this, or when a member_id is submitted directly by parameter matching[1]
	 * the member data is fetched from database, which is then used to substitude
	 * the given alias names in parameter matches[2].
	 *
	 * @param   array   $matches  Array of strings returned by regular expression matching
	 * @return  string
	 */
	protected function wickedteamContactinfoReplacer($matches)
	{
		// Get the global JDatabase object
		$database = JFactory::getDBO();

		/*
		 * Extract selector ('id' or 'query') out of parameter matches[1]. Therefore
		 * matches[1] must contain a string separated by '='. The selector is then the
		 * first part in front of the '=' while the selectionValue is located behind '='
		 */
		$selector = strtok($matches[1], "=");
		$selectionValue = strtok("=");

		// Default assignment in case of invalid parameters
		// matches[0] contains the complete found regex
		$contactHtml = $matches[0];

		$wickedteamMemberId = -1;

		// If wickedteam member id is given directly
		if ($selector == "id")
		{
			// Get the integer value of $selectionValue
			$wickedteamMemberId = intval($selectionValue);
		}

		// If wickedteam member id should be examined by query parameters
		elseif ($selector == "query")
		{
			/*
			 * A query is specified by the following syntax:
			 * query=alias_value1:key_value1&alias_value2:key_value2&...
			 * Generate array out of parts separated by '&'
			 */
			$parts = explode("&amp;", $selectionValue);

			// Foreach element with syntax "alias_value:key_value"
			foreach ($parts as $value)
			{
				/*
				 * Setup associative array and push it into array $whereConditions[]
				 * [alias] => alias_value
				 * [value] => key_value
				 */
				$whereConditions[] = array_combine(array('alias', 'value'), array(strtok($value, ":"), strtok(":")));
			}

			// SQL statement to fetch data which is specified by alias name and field value
			$SQL = "SELECT f.item_id
					FROM #__fields_values AS f
					LEFT JOIN #__fields AS a ON a.id = f.field_id
					WHERE a.name = '%s' AND f.value LIKE '%s'";

			/*
			 * This for loop is used to encapsulate the SQL statements in such a way
			 * that first SELECT statement is used to generate a recordset where all
			 * elements match the given field alias name and field value. For example field alias
			 * name is 'lastname' and field value is 'Mustermann'.
			 * The next loop does the same with the next element of array where_condition,
			 * but returns only this data records where wickedteam member_id is within
			 * the previous record set which is done by the 'IN' statement
			 */
			for ($i = 0; $i < count($whereConditions); $i++)
			{
				if ($i == 0)
				{
					$statement = sprintf($SQL, $whereConditions[$i]['alias'], $whereConditions[$i]['value']);
				}
				else
				{
					$statement = sprintf($SQL, $whereConditions[$i]['alias'], $whereConditions[$i]['value']) .
					sprintf(" AND f.item_id IN(%s)", $statement);
				}
			}

			$database->setQuery($statement);
			$wickedteamMemberData = $database->loadAssoclist();

			// If record count is larger than one
			if (count($wickedteamMemberData) > 1)
			{
				return sprintf(JText::_('PLG_WICKEDTEAM_CONTACTINFO_QUERY_NOT_UNIQUE'), count($wickedteamMemberData));
			}
			// If no record was found
			elseif (count($wickedteamMemberData) == 0)
			{
				return JText::_('PLG_WICKEDTEAM_CONTACTINFO_QUERY_NO_RECORD');
			}

			$wickedteamMemberId = intval($wickedteamMemberData[0]['item_id']);
		}

		// If a wickedteam member was found
		if ($wickedteamMemberId != -1)
		{
			$query = $database->getQuery(true);
			$query->clear();
			$query->select('f.value, a.title, a.name');
			$query->from('#__fields_values AS f');
			$query->leftjoin('#__fields AS a ON a.id = f.field_id');
			$query->where('f.item_id = ' . $wickedteamMemberId);
			$query->order('a.id');
			$database->setQuery($query);
			$wickedteamMemberData = $database->loadAssoclist();

			// Add wickedteam member id as requestable parameter
			$buffer['value'] = $wickedteamMemberId;
			$buffer['title'] = 'id';
			$buffer['name'] = 'id';
			$wickedteamMemberData[] = $buffer;

			// print("<pre>");
			// print_r($wickedteamMemberData);
			// print("</pre>");die();

			// Parameter
			$cssPrefix = $this->params->get("prefix_class", "wci_");
			$useCssStyling = intval($this->params->get("use_css_styling"));

			if ($useCssStyling)
			{
				$contactHtml = "<span class=\"" . $cssPrefix . "member_data\">" .
								// Variable matches[2] contains the values between the {wickedteamcontactinfo} tags
								$matches[2] .
								"</span>";
			}
			else
			{
				$contactHtml = $matches[2];
			}

			// Loop over all wickedteam fields, which was found for this member
			foreach ($wickedteamMemberData as $wickedteamField)
			{
				$style = "<span class=\"" . $cssPrefix . "field-" . $wickedteamField['name'] . "\">";

				$description = "";
				$valueMatch = array();

				// Regular expression to search [...;...]
				// This is used if not only the field value, but also an additional text should be printed
				$regex = "#\[" . $wickedteamField['name'] . ";(.*?)\]#s";

				if (preg_match($regex, $contactHtml, $valueMatch))
				{
					$description = $valueMatch[1];
					$contactHtml = preg_replace($regex, "[" . $wickedteamField['name'] . "]", $contactHtml);
				}

				if ($useCssStyling == 1)
				{
					$contactHtml = str_replace('[' . $wickedteamField['name'] . ']', $style . $description . $wickedteamField['value'] . "</span>", $contactHtml);
				}
				else
				{
					$contactHtml = str_replace('[' . $wickedteamField['name'] . ']', $description . $wickedteamField['value'], $contactHtml);
				}
			}
		}

		return $contactHtml;
	}
}
