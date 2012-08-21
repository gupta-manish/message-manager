<ul>
	<?php if (AuthComponent::user('id')) { ?>
		<li><?php echo $this->Html->link(__('Received'), array('controller' => 'messages', 'action' => 'index', '?' => array('is_outbound' => 0))); ?></li>
		<li><?php echo $this->Html->link(__('Sent'), array('controller' => 'messages', 'action' => 'index', '?' => array('is_outbound' => 1))); ?></li>
		<li><?php echo $this->Html->link(__('Sources'), array('controller' => 'MessageSources', 'action' => 'index')); ?></li>
		<li><?php echo $this->Html->link(__('Users'), array('controller' => 'users', 'action' => 'index')); ?></li>
		<li><?php echo $this->Html->link(__('Activity'), array('controller' => 'actions', 'action' => 'index')); ?></li>
		<?php if (Configure::read('enable_dummy_client')==1) { ?> 
			<li><?php echo $this->Html->link(__('Dummy client'), array('controller' => 'MessageSources', 'action' => 'client')); ?></li>
		<?php } ?>
	<?php } else { ?>
		<li><?php echo $this->Html->link(__('Log in'), array('controller' => 'users', 'action' => 'login')); ?></li>
	<?php } ?>
</ul>
