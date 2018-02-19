<?php
/**
 * 
 * Prime Ban to Group
 * 
 * @copyright (c) 2014 Wolfsblvt ( www.pinkes-forum.de )
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 * @author Clemens Husung (Wolfsblvt)
 * 
 * Original code by primehalo (https://www.phpbb.com/community/memberlist.php?mode=viewprofile&u=183323)
 * Thanks to him for let me convert his MOD.
 */

namespace wolfsblvt\primebantogroup\acp;

class primebantogroup_module
{
	/** @var string The currenct action */
	public $u_action;

	/** @var \phpbb\config\config */
	public $new_config = array();

	/** @var string form key */
	public $form_key;

	/** @var \wolfsblvt\primebantogroup\core\primebantogroup */
	protected $primeban;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\request\request */
	protected $request;

	public function main($id, $mode)
	{
		global $phpbb_container;

		// Initialization
		$this->primeban		= $phpbb_container->get('wolfsblvt.primebantogroup.primebantogroup');
		$this->config		= $phpbb_container->get('config');
		$this->db			= $phpbb_container->get('dbal.conn');
		$this->user			= $phpbb_container->get('user');
		$this->template		= $phpbb_container->get('template');
		$this->request		= $phpbb_container->get('request');

		$action = $this->request->variable('action', '', true);
		$submit = ($this->request->is_set_post('submit')) ? true : false;

		$this->form_key = 'acp_primebantogroup';
		add_form_key($this->form_key);

		#region Ajax actions
		if ($action)
		{
			switch ($action)
			{
				case 'resync_groups':
					if ($this->request->is_ajax())
					{
						$this->primeban->resync_banned_groups();
						// add_log('admin', 'LOG_PBTG_RESYNCED');

						trigger_error('PBTG_RESYNC_SUCCESS');
					}
				break;

				default:
					if ($this->request->is_ajax())
					{
						trigger_error('PBTG_INVALID');
					}
			}
		}
		#endregion

		$display_vars = array(
			'title' => 'PBTG_TITLE_ACP',
			'vars'    => array(
				'legend1'										=> 'PBTG_SETTINGS',
				'wolfsblvt.primebantogroup.resync'				=> array('lang' => 'PBTG_RESYNC',		'validate' => 'bool',		'type' => 'custom',					'explain' => true,	'method' => 'resync_groups'),
				'wolfsblvt.primebantogroup.check_gc'			=> array('lang' => 'PBTG_CHECK',		'validate' => 'int:0',		'type' => 'number:0',				'explain' => true,	'append' => ' ' . $this->user->lang['SECONDS']),
				'wolfsblvt.primebantogroup.inactive_group'		=> array('lang' => 'PBTG_ACT_INACTIVE',	'validate' => 'bool',		'type' => 'radio:enabled_disabled',	'explain' => true),
				'legend2'										=> 'ACP_SUBMIT_CHANGES'
			),
		);

		$special_functions = array(
			'wolfsblvt.primebantogroup.resync' => null,
		);

		#region Submit
		if ($submit)
		{
			$submit = $this->do_submit_stuff($display_vars, $special_functions);

			// If the submit was valid, so still submitted
			if ($submit)
			{
				trigger_error($this->user->lang('CONFIG_UPDATED') . adm_back_link($this->u_action), E_USER_NOTICE);
			}
		}
		#endregion

		$this->generate_stuff_for_cfg_template($display_vars);

		// Output page template file
		$this->tpl_name = 'acp_primebantogroup';
		$this->page_title = $this->user->lang($display_vars['title']);
	}

	/**
	 * Triggers resync for all groups used in primebantogroup.
	 * 
	 * @param string $value The value
	 * @param string $key The key
	 * @return string The formatted string of this item
	 */
	public function resync_groups($value, $key)
	{
		$action = append_sid($this->u_action, 'action=resync_groups');
		return '<a href="' . $action . '" data-ajax="true"><input class="button2" type="submit" id="' . $key . '_enable" name="' . $key . '_enable" value="' . $this->user->lang['RUN'] . '" /></a>';
	}

	/**
	 * Abstracted method to do the submit part of the acp. Checks values, saves them
	 * and displays the message.
	 * If error happens, Error is shown and config not saved. (so this method quits and returns false.
	 * 
	 * @param array $display_vars The display vars for this acp site
	 * @param array $special_functions Assoziative Array with config values where special functions should run on submit instead of simply save the config value. Array should contain 'config_value' => function ($this) { function code here }, or 'config_value' => null if no function should run.
	 * @return bool Submit valid or not.
	 */
	protected function do_submit_stuff($display_vars, $special_functions = array())
	{
		$this->new_config = $this->config;
		$cfg_array = ($this->request->is_set('config')) ? $this->request->variable('config', array('' => '')) : $this->new_config;
		$error = isset($error) ? $error : array();

		validate_config_vars($display_vars['vars'], $cfg_array, $error);

		if (!check_form_key($this->form_key))
		{
			$error[] = $this->user->lang['FORM_INVALID'];
		}

		// Do not write values if there is an error
		if (sizeof($error))
		{
			$submit = false;
			return false;
		}

		// We go through the display_vars to make sure no one is trying to set variables he/she is not allowed to...
		foreach ($display_vars['vars'] as $config_name => $null)
		{
			// We want to skip that, or run the function. (We do this before checking if there is a request value set for it,
			// cause maybe we want to run a function anyway, based on whatever. We can check stuff manually inside this function)
			if (is_array($special_functions) && array_key_exists($config_name, $special_functions))
			{
				$func = $special_functions[$config_name];
				if (isset($func) && is_callable($func))
					$func();

				continue;
			}

			if (!isset($cfg_array[$config_name]) || strpos($config_name, 'legend') !== false)
			{
				continue;
			}

			// Sets the config value
			$this->new_config[$config_name] = $cfg_array[$config_name];
			$this->config->set($config_name, $cfg_array[$config_name]);
		}

		return true;
	}

	/**
	 * Abstracted method to generate acp configuration pages out of a list of display vars, using
	 * the function build_cfg_template().
	 * Build configuration template for acp configuration pages
	 * 
	 * @param array $display_vars The display vars for this acp site
	 */
	protected function generate_stuff_for_cfg_template($display_vars)
	{
		$this->new_config = $this->config;
		$cfg_array = ($this->request->is_set('config')) ? $this->request->variable('config', array('' => '')) : $this->new_config;
		$error = isset($error) ? $error : array();

		validate_config_vars($display_vars['vars'], $cfg_array, $error);

		foreach ($display_vars['vars'] as $config_key => $vars)
		{
			if (!is_array($vars) && strpos($config_key, 'legend') === false)
			{
				continue;
			}

			if (strpos($config_key, 'legend') !== false)
			{
				$this->template->assign_block_vars('options', array(
					'S_LEGEND'		=> true,
					'LEGEND'		=> (isset($this->user->lang[$vars])) ? $this->user->lang[$vars] : $vars)
				);

				continue;
			}

			$type = explode(':', $vars['type']);

			$l_explain = '';
			if ($vars['explain'] && isset($vars['lang_explain']))
			{
				$l_explain = (isset($this->user->lang[$vars['lang_explain']])) ? $this->user->lang[$vars['lang_explain']] : $vars['lang_explain'];
			}
			else if ($vars['explain'])
			{
				$l_explain = (isset($this->user->lang[$vars['lang'] . '_EXPLAIN'])) ? $this->user->lang[$vars['lang'] . '_EXPLAIN'] : '';
			}

			$content = build_cfg_template($type, $config_key, $this->new_config, $config_key, $vars);

			if (empty($content))
			{
				continue;
			}

			$this->template->assign_block_vars('options', array(
				'KEY'				=> $config_key,
				'TITLE'				=> (isset($this->user->lang[$vars['lang']])) ? $this->user->lang[$vars['lang']] : $vars['lang'],
				'S_EXPLAIN'			=> $vars['explain'],
				'TITLE_EXPLAIN'		=> $l_explain,
				'CONTENT'			=> $content,
			));

			//unset($display_vars['vars'][$config_key]);
		}

		$this->template->assign_vars(array(
			'S_ERROR'			=> (sizeof($error)) ? true : false,
			'ERROR_MSG'			=> implode('<br />', $error),

			'U_ACTION'			=> $this->u_action)
		);
	}
}
