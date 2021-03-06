<?php
/**
 * An overall definition of a workflow
 *
 * The workflow definition has a series of steps to it. Each step has a series of possible transitions
 * that it can take - the first one that meets certain criteria is followed, which could lead to
 * another step.
 *
 * A step is either manual or automatic; an example 'manual' step would be requiring a person to review
 * a document. An automatic step might be to email a group of people, or to publish documents.
 * Basically, a manual step requires the interaction of someone to pick which action to take, an automatic
 * step will automatically determine what to do once it has finished.
 *
 * @author  marcus@silverstripe.com.au
 * @license BSD License (http://silverstripe.org/bsd-license/)
 * @package advancedworkflow
 */
class WorkflowDefinition extends DataObject {
	public static $db = array(
		'Title'       => 'Varchar(128)',
		'Description' => 'Text',
		'RemindDays'  => 'Int',
		'Sort'        => 'Int'
	);

	public static $default_sort = 'Sort';

	public static $has_many = array(
		'Actions'   => 'WorkflowAction',
		'Instances' => 'WorkflowInstance'
	);

	/**
	 * By default, a workflow definition is bound to a particular set of users or groups.
	 *
	 * This is covered across to the workflow instance - it is up to subsequent
	 * workflow actions to change this if needbe.
	 *
	 * @var array
	 */
	public static $many_many = array(
		'Users' => 'Member',
		'Groups' => 'Group'
	);

	public static $icon = 'advancedworkflow/images/definition.png';

	public static $default_workflow_title_base = 'My Workflow';
	
	public static $default_workflow_title_incr = 1;

	public static $workflow_defs = array();

	/**
	 * Gets the action that first triggers off the workflow
	 *
	 * @return WorkflowAction
	 */
	public function getInitialAction() {
		if($actions = $this->Actions()) return $actions->First();
	}

	/**
	 * Ensure a sort value is set
	 */
	public function onBeforeWrite() {
		if(!$this->Sort) {
			$this->Sort = DB::query('SELECT MAX("Sort") + 1 FROM "WorkflowDefinition"')->value();
		}
		// Use default title if no title
		if(!$this->ID) {
			$this->getDefaultWorkflowTitle();
		}
		parent::onBeforeWrite();
	}

	/**
	 * @return int
	 */
	public function numChildren() {
		return count($this->Actions());
	}

	/**
	 */
	public function getCMSFields() {
		
		$cmsUsers = Member::mapInCMSGroups();
		
		$fields = new FieldList(new TabSet('Root'));

		$fields->addFieldToTab('Root.Main', new TextField('Title', _t('WorkflowDefinition.TITLE', 'Title')));
		$fields->addFieldToTab('Root.Main', new TextareaField('Description', _t('WorkflowDefinition.DESCRIPTION', 'Description')));
		if($this->ID) {
			$fields->addFieldToTab('Root.Main', new CheckboxSetField('Users', _t('WorkflowDefinition.USERS', 'Users'), $cmsUsers));
			$fields->addFieldToTab('Root.Main', new TreeMultiselectField('Groups', _t('WorkflowDefinition.GROUPS', 'Groups'), 'Group'));
		}

		if (class_exists('AbstractQueuedJob')) {
			$before = _t('WorkflowDefinition.SENDREMINDERDAYSBEFORE', 'Send reminder email after ');
			$after  = _t('WorkflowDefinition.SENDREMINDERDAYSAFTER', ' days without action.');

			$fields->addFieldToTab('Root.Main', new FieldGroup(
				_t('WorkflowDefinition.REMINDEREMAIL', 'Reminder Email'),
				new LabelField('ReminderEmailBefore', $before),
				new NumericField('RemindDays', ''),
				new LabelField('ReminderEmailAfter', $after)
			));
		}

		if($this->ID) {
			$fields->addFieldToTab('Root.Main', new WorkflowField(
				'Workflow', _t('WorkflowDefinition.WORKFLOW', 'Workflow'), $this 
			));
		} else {
			$message = _t(
				'WorkflowDefinition.ADDAFTERSAVING',
				'You can add workflow steps after you save for the first time.'
			);
			$fields->addFieldToTab('Root.Main', new LiteralField(
				'AddAfterSaving', "<p class='message notice'>$message</p>"
			));
		}

		if($this->ID && Permission::check('VIEW_ACTIVE_WORKFLOWS')) {
			$active = $this->Instances()->filter(array(
				'WorkflowStatus' => array('Active', 'Paused')
			));

			$active = new GridField(
				'Active',
				'Active Workflow Instances',
				$active,
				new GridFieldConfig_RecordEditor());

			$active->getConfig()->removeComponentsByType('GridFieldAddNewButton');
			$active->getConfig()->removeComponentsByType('GridFieldDeleteAction');

			if(!Permission::check('REASSIGN_ACTIVE_WORKFLOWS')) {
				$active->getConfig()->removeComponentsByType('GridFieldEditButton');
				$active->getConfig()->addComponent(new GridFieldViewButton());
				$active->getConfig()->addComponent(new GridFieldDetailForm());
			}

			$completed = $this->Instances()->filter(array(
				'WorkflowStatus' => array('Complete', 'Cancelled')
			));

			$config = new GridFieldConfig_Base();
			$config->addComponent(new GridFieldEditButton());
			$config->addComponent(new GridFieldDetailForm());
			
			$completed = new GridField(
				'Completed',
				'Completed Workflow Instances',
				$completed,
				$config);

			$fields->addFieldToTab('Root.Active', $active);
			$fields->addFieldToTab('Root.Completed', $completed);
		}

		return $fields;
	}

	/*
	 * Checks if a workflow-title already exists and offers a suitable default when users attempt to create a title-less workflow
	 *
	 * @see @link self::$default_workflow_title_base
	 * @see @link self::$default_workflow_title_incr
	 * @return string A new default workflow title
	 * @todo Filter/alter query for only current-user's workflows. Avoids confusion when other users already have 'My Workflow 1' and user sees 'My Workflow 2'
	 */
	public function getDefaultWorkflowTitle() {
		$this->setWorkflowDefinitions();
		if($this->Title) {
			return;
		}
		$base = self::$default_workflow_title_base;
		$defs = $this->getWorkflowDefinitions();
		$tmp = array();
		foreach($defs as $def) {
			if(preg_match("#$base#",$def)) {
				$last_part = preg_split("#\s#",$def,-1,PREG_SPLIT_NO_EMPTY);
				$last_part = end($last_part);
				if(in_array($base.' '.$last_part,$defs)) {
					array_push($tmp,$last_part);
				}
			}
		}
		if(count($tmp)>0) {
			sort($tmp,SORT_NUMERIC);
			self::$default_workflow_title_incr = end($tmp)+1;
		}
		$this->Title = self::$default_workflow_title_base.' '.self::$default_workflow_title_incr;
	}

	public function getWorkflowDefinitions() {
		return self::$workflow_defs;
	}

	/*
	 * Setter to "cache" some basic workflow definiton data
	 */
	private function setWorkflowDefinitions() {
		$workflow_defs = DataObject::get('WorkflowDefinition');
		self::$workflow_defs = $workflow_defs->map()->toArray();
	}
}
