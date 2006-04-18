<?php
/** 
*
* @package acp
* @version $Id$
* @copyright (c) 2005 phpBB Group 
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
* @package acp
*/
class acp_database
{
	var $u_action;

	function main($id, $mode)
	{
		global $db, $user, $auth, $template, $table_prefix;
		global $config, $SID, $phpbb_root_path, $phpbb_admin_path, $phpEx;
		
		$user->add_lang('acp/database');

		$this->tpl_name = 'acp_database';
		$this->page_title = 'ACP_DATABASE';

		$action	= request_var('action', '');
		$submit = (isset($_POST['submit'])) ? true : false;

		$template->assign_vars(array(
			'MODE'	=> $mode
		));

		switch ($mode)
		{
			case 'backup':

				switch ($action)
				{
					case 'download':
						$type	= request_var('type', '');
						$table	= request_var('table', array(''));
						$format	= request_var('method', '');
						$where	= request_var('where', '');

						$store = $download = false;

						if ($where == 'store_and_download' || $where == 'store')
						{
							$store = true;
						}

						if ($where == 'store_and_download' || $where == 'download')
						{
							$download = true;
						}

						@set_time_limit(1200);

						$filename = time();

						// We set up the info needed for our on-the-fly creation :D
						switch ($format)
						{
							case 'text':
								$ext = '.sql';
								$open = 'fopen';
								$write = 'fwrite';
								$close = 'fclose';
								$oper = '';
								$mimetype = 'text/x-sql';
							break;
							case 'bzip2':
								$ext = '.sql.bz2';
								$open = 'bzopen';
								$write = 'bzwrite';
								$close = 'bzclose';
								$oper = 'bzcompress';
								$mimetype = 'application/x-bzip2';
							break;
							case 'gzip':
								$ext = '.sql.gz';
								$open = 'gzopen';
								$write = 'gzwrite';
								$close = 'gzclose';
								$oper = 'gzencode';
								$mimetype = 'application/x-gzip';
							break;
						}

						// We write the file to "store" first (and then compress the file) to not use too much
						// memory. The server process can be easily killed by storing too much data at once.

						
						if ($store == true)
						{
							$file = $phpbb_root_path . 'store/' . $filename . $ext;

							$fp = $open($file, 'w');

							if (!$fp)
							{
								trigger_error('Unable to write temporary file to storage folder');
							}
						}

						if ($download == true)
						{
							$name = $filename . $ext;
							header('Pragma: no-cache');
							header("Content-Type: $mimetype; name=\"$name\"");
							header("Content-disposition: attachment; filename=$name");
						}

						// All of the generated queries go here
						$sql_data = '';
						$sql_data .= "#\n";
						$sql_data .= "# phpBB Backup Script\n";
						$sql_data .= "# Dump of tables for $table_prefix\n";
						$sql_data .= "# DATE : " .  gmdate("d-m-Y H:i:s", $filename) . " GMT\n";
						$sql_data .= "#\n";

						switch (SQL_LAYER)
						{
							case 'sqlite':
								$sql_data .= "BEGIN TRANSACTION;\n";
							break;

							case 'postgres':
								$sql_data .= "BEGIN;\n";

							case 'mssql':
							case 'mssql_odbc':
								$sql_data .= "BEGIN TRANSACTION\nGO\n";
							break;
						}

						foreach ($table as $table_name)
						{
							// Get the table structure
							if ($type == 'full' || $type == 'structure')
							{
								switch (SQL_LAYER)
								{
									case 'mysqli':
									case 'mysql4':
									case 'mysql':
									case 'sqlite';
										$sql_data .= '# Table: ' . $table_name . "\n";
										$sql_data .= "DROP TABLE IF EXISTS $table_name;\n";
									break;

									case 'postgres':
									case 'mssql':
										$sql_data .= '# Table: ' . $table_name . "\n";
										$sql_data .= "DROP TABLE $table_name;\nGO\n";
									break;

									default:
										trigger_error('KungFuDeathGrip');
									break;
								}
								$sql_data .= $this->get_table_structure($table_name);
							}
							// Now write the data for the first time. :)
							if ($store == true)
							{
								$write($fp, $sql_data);
							}

							if ($download == true)
							{
								if (!empty($oper))
								{
									echo $oper($sql_data);
								}
								else
								{
									echo $sql_data;
								}
							}

							$sql_data = '';

							// Data
							if ($type == 'full' || $type == 'data')
							{
								$sql_data .= "\n";

								switch (SQL_LAYER)
								{
									case 'mysqli':

										$sql = "SELECT * FROM $table_name";
										$result = mysqli_query($db->db_connect_id, $sql, MYSQLI_USE_RESULT);
										if ($result != false)
										{
											$fields_cnt = mysqli_num_fields($result);

											// Get field information
											$field = mysqli_fetch_fields($result);
											$field_set = array();

											for ($j = 0; $j < $fields_cnt; $j++)
											{
												$field_set[$j] = $field[$j]->name;
											}

											$search			= array('\\', "'", "\x00", "\x0a", "\x0d", "\x1a");
											$replace		= array('\\\\\\\\', "''", '\0', '\n', '\r', '\Z');
											$fields			= implode(', ', $field_set);
											$values			= array();
											$schema_insert	= 'INSERT INTO ' . $table_name . ' (' . $fields . ') VALUES (';

											while ($row = $db->sql_fetchrow($result))
											{
												for ($j = 0; $j < $fields_cnt; $j++)
												{
													if (!isset($row[$j]) || is_null($row[$j]))
													{
														$values[] = 'NULL';
													}
													else if (($field[$j]->flags & 32768) && !($field[$j]->flags & 1024))
													{
														$values[] = $row[$j];
													}
													else
													{
														$values[] = "'" . str_replace($search, $replace, $row[$j]) . "'";
													}
												}
												$sql_data .= $schema_insert . implode(', ', $values) . ");\n";

												if ($store == true)
												{
													$write($fp, $sql_data);
												}

												if ($download == true)
												{
													if (!empty($oper))
													{
														echo $oper($sql_data);
													}
													else
													{
														echo $sql_data;
													}
												}
												$sql_data = '';

												$values	= array();
											}
											mysqli_free_result($result);
										}
									break;

									case 'mysql4':
									case 'mysql':
	
										$sql = "SELECT * FROM $table_name";
										$result = mysql_unbuffered_query($sql, $db->db_connect_id);

										if ($result != false)
										{
											$fields_cnt = mysql_num_fields($result);

											// Get field information
											$field = array();
											for ($i = 0; $i < $fields_cnt; $i++) 
											{
												$field[] = mysql_fetch_field($result, $i);
											}
											$field_set = array();
											
											for ($j = 0; $j < $fields_cnt; $j++)
											{
												$field_set[$j] = $field[$j]->name;
											}

											$search			= array('\\', "'", "\x00", "\x0a", "\x0d", "\x1a");
											$replace		= array('\\\\\\\\', "''", '\0', '\n', '\r', '\Z');
											$fields			= implode(', ', $field_set);
											$schema_insert	= 'INSERT INTO ' . $table_name . ' (' . $fields . ') VALUES (';

											while ($row = $db->sql_fetchrow($result))
											{
												$values = array();

												for ($j = 0; $j < $fields_cnt; $j++)
												{
													if (!isset($row[$j]) || is_null($row[$j]))
													{
														$values[] = 'NULL';
													}
													else if ($field[$j]->numeric && ($field[$j]->type !== 'timestamp'))
													{
														$values[] = $row[$j];
													}
													else
													{
														$values[] = "'" . str_replace($search, $replace, $row[$j]) . "'";
													}
												}
												$sql_data .= $schema_insert . implode(', ', $values) . ");\n";

												if ($store == true)
												{
													$write($fp, $sql_data);
												}

												if ($download == true)
												{
													if (!empty($oper))
													{
														echo $oper($sql_data);
													}
													else
													{
														echo $sql_data;
													}
												}
												$sql_data = '';
											}
											mysql_free_result($result);
										}
									break;
	
									case 'sqlite':

										$col_types = sqlite_fetch_column_types($table_name, $db->db_connect_id);
										$sql = "SELECT * FROM $table_name";
										$result = $db->sql_query($sql);

										while ($row = $db->sql_fetchrow($result))
										{
											$names = $data = array();
											foreach ($row as $row_name => $row_data)
											{
												$names[] = $row_name;

												// Figure out what this data is, escape it properly
												if (is_null($row_data))
												{
													$row_data = 'NULL';
												}
												else if ($row_data == '')
												{
													$row_data = "''";
												}
												else if (strpos($col_types[$row_name], 'text') !== false || strpos($col_types[$row_name], 'char') !== false)
												{
													$row_data = "'" . $row_data . "'";
												}

												$data[] = $row_data;
											}
											$sql_data .= 'INSERT INTO ' . $table_name . ' (' . implode(', ', $names) . ') VALUES ('. implode(', ', $data) .");\n";

											if ($store == true)
											{
												$write($fp, $sql_data);
											}

											if ($download == true)
											{
												if (!empty($oper))
												{
													echo $oper($sql_data);
												}
												else
												{
													echo $sql_data;
												}
											}
											$sql_data = '';

										}
										$db->sql_freeresult($result);
									break;

									case 'postgres':

										$aryType = $aryName = array();
										
										// Grab all of the data from current table.
										$sql = "SELECT * FROM {$table_name}";
										$result = $db->sql_query($sql);

										$i_num_fields = pg_num_fields($result);

										for ($i = 0; $i < $i_num_fields; $i++)
										{
											$aryType[] = pg_field_type($result, $i);
											$aryName[] = pg_field_name($result, $i);
										}

										while ($row = $db->sql_fetchrow($result))
										{
											$schema_vals = $schema_fields = array();

											// Build the SQL statement to recreate the data.
											for ($i = 0; $i < $i_num_fields; $i++)
											{
												$strVal = $row[$aryName[$i]];

												if (preg_match('#char|text|bool#i', $aryType[$i]))
												{
													$strQuote = "'";
													$strEmpty = '';
													$strVal = addslashes($strVal);
												}
												else if (preg_match('#date|timestamp#i', $aryType[$i]))
												{
													if (empty($strVal))
													{
														$strQuote = '';
													}
													else
													{
														$strQuote = "'";
													}
												}
												else
												{
													$strQuote = '';
													$strEmpty = 'NULL';
												}

												if (empty($strVal) && $strVal !== '0')
												{
													$strVal = $strEmpty;
												}

												$schema_vals[] = $strQuote . $strVal . $strQuote;
												$schema_fields[] = $aryName[$i];
											}

											// Take the ordered fields and their associated data and build it
											// into a valid sql statement to recreate that field in the data.
											$sql_data .= "INSERT INTO $table_name (" . implode(', ', $schema_fields) . ') VALUES(' . implode(', ', $schema_vals) . ");\n";

											if ($store == true)
											{
												$write($fp, $sql_data);
											}

											if ($download == true)
											{
												if (!empty($oper))
												{
													echo $oper($sql_data);
												}
												else
												{
													echo $sql_data;
												}
											}

											$sql_data = '';

										}
										$db->sql_freeresult($result);
									break;

									case 'mssql':
									case 'mssql_odbc':
										$aryType = $aryName = array();
										
										// Grab all of the data from current table.
										$sql = "SELECT * FROM {$table_name}";
										$result = $db->sql_query($sql);

										$retrieved_data = $db->sql_numrows($result);

										if ($retrieved_data)
										{
											$sql_data .= "\nSET IDENTITY_INSERT $table_name ON\n";
										}

										$i_num_fields = mssql_num_fields($result);

										for ($i = 0; $i < $i_num_fields; $i++)
										{
											$aryType[] = mssql_field_type($result, $i);
											$aryName[] = mssql_field_name($result, $i);
										}

										while ($row = $db->sql_fetchrow($result))
										{
											$schema_vals = $schema_fields = array();

											// Build the SQL statement to recreate the data.
											for ($i = 0; $i < $i_num_fields; $i++)
											{
												$strVal = $row[$aryName[$i]];

												if (preg_match('#char|text|bool#i', $aryType[$i]))
												{
													$strQuote = "'";
													$strEmpty = '';
													$strVal = addslashes($strVal);
												}
												else if (preg_match('#date|timestamp#i', $aryType[$i]))
												{
													if (empty($strVal))
													{
														$strQuote = '';
													}
													else
													{
														$strQuote = "'";
													}
												}
												else
												{
													$strQuote = '';
													$strEmpty = 'NULL';
												}

												if (empty($strVal) && $strVal !== '0')
												{
													$strVal = $strEmpty;
												}

												$schema_vals[] = $strQuote . $strVal . $strQuote;
												$schema_fields[] = $aryName[$i];
											}

											// Take the ordered fields and their associated data and build it
											// into a valid sql statement to recreate that field in the data.
											$sql_data .= "INSERT INTO $table_name (" . implode(', ', $schema_fields) . ') VALUES(' . implode(', ', $schema_vals) . ");\n";

											if ($store == true)
											{
												$write($fp, $sql_data);
											}

											if ($download == true)
											{
												if (!empty($oper))
												{
													echo $oper($sql_data);
												}
												else
												{
													echo $sql_data;
												}
											}

											$sql_data = '';

										}

										if ($retrieved_data)
										{
											$sql_data .= "\nSET IDENTITY_INSERT $table_name OFF\n";
										}

										$db->sql_freeresult($result);
									break;

									default:
										trigger_error('KungFuDeathGrip');
								}
							}
						}

						switch (SQL_LAYER)
						{
							case 'sqlite':
							case 'postgres':
								$sql_data .= "COMMIT;";
							break;

							case 'mssql':
							case 'mssql_odbc':
								$sql_data .= "COMMIT\nGO";
							break;
						}
						
						if ($store == true)
						{
							$write($fp, $sql_data);
							$close($fp);
						}

						if ($download == true)
						{
							if (!empty($oper))
							{
								echo $oper($sql_data);
							}
							else
							{
								echo $sql_data;
							}
							exit;
						}

						unset($sql_data);

						add_log('admin', 'LOG_DB_BACKUP');
						trigger_error($user->lang['BACKUP_SUCCESS']);
					break;

					default:
						$tables = array();
						switch (SQL_LAYER)
						{
							case 'sqlite':
								$sql = "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name";
								$result = $db->sql_query($sql);
								while ($row = $db->sql_fetchrow($result))
								{
									if (strpos($row['name'] . '_', $table_prefix) === 0)
									{
										$tables[] = $row['name'];
									}
								}
								$db->sql_freeresult($result);
							break;
							
							case 'mysqli':
							case 'mysql4':
							case 'mysql':
								$sql = "SHOW TABLES LIKE '{$table_prefix}%'";
								$result = $db->sql_query($sql);
								while ($row = $db->sql_fetchrow($result))
								{
									$tables[] = current($row);
								}
								$db->sql_freeresult($result);
							break;

							case 'postgres':
								$sql = "SELECT relname FROM pg_stat_user_tables ORDER BY relname;";
								$result = $db->sql_query($sql);
								while ($row = $db->sql_fetchrow($result))
								{
									if (strpos($row['relname'] . '_', $table_prefix) === 0)
									{
										$tables[] = $row['relname'];
									}
								}
								$db->sql_freeresult($result);
							break;

							case 'mssql':
							case 'mssql_odbc':
								$sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME";
								$result = $db->sql_query($sql);
								while ($row = $db->sql_fetchrow($result))
								{
									if (strpos($row['TABLE_NAME'] . '_', $table_prefix) === 0)
									{
										$tables[] = $row['TABLE_NAME'];
									}
								}
								$db->sql_freeresult($result);
							break;

							default:
								trigger_error('KungFuDeathGrip');
						}

						foreach ($tables as $table)
						{
							$template->assign_block_vars('tables', array(
								'TABLE'	=> $table
							));
						}

						$template->assign_vars(array(
							'U_ACTION'	=> $this->u_action . '&amp;action=download'
						));
						
						$methods = array('text');
						$available_methods = array('gzip' => 'zlib', 'bzip2' => 'bz2');

						foreach ($available_methods as $type => $module)
						{
							if (!@extension_loaded($module))
							{
								continue;
							}
							$methods[] = $type;
						}

						foreach ($methods as $type)
						{
							$template->assign_block_vars('methods', array(
								'TYPE'	=> $type
							));
						}
					break;
				}
			break;

			case 'restore':
				switch ($action)
				{
					case 'submit':
						$delete = request_var('delete', '');

						if ($delete)
						{
							$file = request_var('file', '');
							unlink($phpbb_root_path . 'store/' . $file);
							trigger_error($user->lang['BACKUP_SUCCESS']);
						}

						$file = request_var('file', '');
						$data = '';

						preg_match('#^(\d{10})\.(sql(?:\.(?:gz|bz2))?)$#', $file, $matches);

						switch ($matches[2])
						{
							case 'sql':
								$data = file_get_contents($phpbb_root_path . 'store/' . $matches[0]);
							break;
							case 'sql.bz2':
								$data = bzdecompress(file_get_contents($phpbb_root_path . 'store/' . $matches[0]));
							break;
							case 'sql.gz':
								$data = implode(gzfile($phpbb_root_path . 'store/' . $matches[0]));
							break;
						}

						$download = request_var('download', '');

						if ($download)
						{
							$name = $matches[0];

							switch ($matches[2])
							{
								case 'sql':
									$mimetype = 'text/x-sql';
								break;
								case 'sql.bz2':
									$mimetype = 'application/x-bzip2';
								break;
								case 'sql.gz':
									$mimetype = 'application/x-gzip';
								break;
							}

							header('Pragma: no-cache');
							header("Content-Type: $mimetype; name=\"$name\"");
							header("Content-disposition: attachment; filename=$name");
							echo $data;
							die;
						}

						if (!empty($data))
						{
							// Strip out sql comments...
							remove_remarks($data);
							$pieces = split_sql_file($data, ';');

							$sql_count = count($pieces);
							for($i = 0; $i < $sql_count; $i++)
							{
								$sql = trim($pieces[$i]);

								if (!empty($sql) && $sql[0] != '#')
								{
									$db->sql_query($sql);
								}
							}
						}
						add_log('admin', 'LOG_DB_RESTORE');
						trigger_error($user->lang['RESTORE_SUCCESS']);
					break;

					default:
						$selected = $stop = false;
						$methods = array('sql');
						$available_methods = array('sql.gz' => 'zlib', 'sql.bz2' => 'bz2');

						foreach ($available_methods as $type => $module)
						{
							if (!@extension_loaded($module))
							{
								continue;
							}
							$methods[] = $type;
						}

						$dir = $phpbb_root_path . 'store/';
						$dh = opendir($dir);
						while (($file = readdir($dh)) !== false)
						{
							if (preg_match('#^(\d{10})\.(sql(?:\.(?:gz|bz2))?)$#', $file, $matches))
							{
								$supported = in_array($matches[2], $methods);

								if ($supported == 'true')
								{
									$template->assign_block_vars('files', array(
										'FILE'		=> $file,
										'NAME'		=> gmdate("d-m-Y H:i:s", $matches[1]),
										'SUPPORTED'	=> $supported
									));
								}
							}
						}
						closedir($dh);

						$template->assign_vars(array(
							'U_ACTION'	=> $this->u_action . '&amp;action=submit'
						));
					break;
				}
			break;
		}
	}

	/**
	* Return table structure
	*/
	function get_table_structure($table_name)
	{
		global $db;

		$sql_data = '';

		switch (SQL_LAYER)
		{
			case 'mysqli':
			case 'mysql4':
			case 'mysql':

				$sql_data .= "CREATE TABLE $table_name(\n";
				$rows = array();

				$result = $db->sql_query("SHOW FIELDS FROM $table_name");

				while ($row = $db->sql_fetchrow($result))
				{
					$line = '   ' . $row['Field'] . ' ' . $row['Type'];

					if (!is_null($row['Default']))
					{
						$line .= " DEFAULT '{$row['Default']}'";
					}

					if ($row['Null'] != 'YES')
					{
						$line .= ' NOT NULL';
					}

					if ($row['Extra'] != '')
					{
						$line .= ' ' . $row['Extra'];
					}

					$rows[] = $line;
				}
				$db->sql_freeresult($result);

				$result = $db->sql_query("SHOW KEYS FROM $table_name");

				$index = array();
				while ($row = $db->sql_fetchrow($result))
				{
					$kname = $row['Key_name'];

					if ($kname != 'PRIMARY' && $row['Non_unique'] == 0)
					{
						$kname = "UNIQUE|$kname";
					}

					$index[$kname][] = $row['Column_name'];
				}
				$db->sql_freeresult($result);

				foreach ($index as $key => $columns)
				{
					$line = '   ';

					if ($key == 'PRIMARY')
					{
						$line .= 'PRIMARY KEY (' . implode(', ', $columns) . ')';
					}
					else if (strpos($key, 'UNIQUE') === 0)
					{
						$line .= 'UNIQUE ' . substr($key, 7) . ' (' . implode(', ', $columns) . ')';
					}
					else
					{
						$line .= "KEY $key (" . implode(', ', $columns) . ')';
					}

					$rows[] = $line;
				}

				$sql_data .= implode(",\n", $rows);
				$sql_data .= "\n);\n\n";

			break;

			case 'sqlite':

				$sql = "SELECT sql
					FROM sqlite_master 
					WHERE type = 'table' 
						AND name = '" . $db->sql_escape($table_name) . "'
					ORDER BY type DESC, name;";
				$result = $db->sql_query($sql);
				$row = $db->sql_fetchrow($result);
				$db->sql_freeresult($result);

				// Create Table
				$sql_data .= $row['sql'] . "\n";

				$result = $db->sql_query("PRAGMA index_list('" . $db->sql_escape($table_name) . "');");

				$ar = array();
				while ($row = $db->sql_fetchrow($result))
				{
					$ar[] = $row;
				}
				$db->sql_freeresult($result);
				
				foreach ($ar as $value)
				{
					if (strpos($value['name'], 'autoindex') !== false)
					{
						continue;
					}

					$result = $db->sql_query("PRAGMA index_info('" . $db->sql_escape($value['name']) . "');");

					$fields = array();
					while ($row = $db->sql_fetchrow($result))
					{
						$fields[] = $row['name'];
					}
					$db->sql_freeresult($result);

					$sql_data .= 'CREATE ' . ($value['unique'] ? 'UNIQUE ' : '') . 'INDEX ' . $value['name'] . ' on ' . $table_name . ' (' . implode(', ', $fields) . ");\n";
				}

				$sql_data .= "\n";
			break;

			case 'postgres':
			
				$field_query = "SELECT a.attnum, a.attname AS field, t.typname as type, a.attlen AS length, a.atttypmod as lengthvar, a.attnotnull as notnull
					FROM pg_class c, pg_attribute a, pg_type t
					WHERE c.relname = '" . $db->sql_escape($table_name) . "'
						AND a.attnum > 0
						AND a.attrelid = c.oid
						AND a.atttypid = t.oid
					ORDER BY a.attnum";
				$result = $db->sql_query($field_query);

				$sql_data .= "CREATE TABLE $table_name(\n";
				$lines = array();
				while ($row = $db->sql_fetchrow($result))
				{
					// Get the data from the table
					$sql_get_default = "SELECT d.adsrc AS rowdefault
						FROM pg_attrdef d, pg_class c
						WHERE (c.relname = '" . $db->sql_escape($table_name) . "')
							AND (c.oid = d.adrelid)
							AND d.adnum = " . $row['attnum'];
					$def_res = $db->sql_query($sql_get_default);

					if (!$def_res)
					{
						unset($row['rowdefault']);
					}
					else
					{
						$row['rowdefault'] = $db->sql_fetchfield('rowdefault', 0, $def_res);
					}
					$db->sql_freeresult($def_res);

					if ($row['type'] == 'bpchar')
					{
						// Internally stored as bpchar, but isn't accepted in a CREATE TABLE statement.
						$row['type'] = 'char';
					}

					$line = '  ' . $row['field'] . ' ' . $row['type'];

					if (strpos($row['type'], 'char') !== false)
					{
						if ($row['lengthvar'] > 0)
						{
							$line .= '(' . ($row['lengthvar'] - 4) . ')';
						}
					}

					if (strpos($row['type'], 'numeric') !== false)
					{
						$line .= '(';
						$line .= sprintf("%s,%s", (($row['lengthvar'] >> 16) & 0xffff), (($row['lengthvar'] - 4) & 0xffff));
						$line .= ')';
					}

					if (!empty($row['rowdefault']))
					{
						$line .= ' DEFAULT ' . $row['rowdefault'];
					}

					if ($row['notnull'] == 't')
					{
						$line .= ' NOT NULL';
					}
					
					$lines[] = $line;
				}
				$db->sql_freeresult($result);


				// Get the listing of primary keys.
				$sql_pri_keys = "SELECT ic.relname AS index_name, bc.relname AS tab_name, ta.attname AS column_name, i.indisunique AS unique_key, i.indisprimary AS primary_key
					FROM pg_class bc, pg_class ic, pg_index i, pg_attribute ta, pg_attribute ia
					WHERE (bc.oid = i.indrelid)
						AND (ic.oid = i.indexrelid)
						AND (ia.attrelid = i.indexrelid)
						AND	(ta.attrelid = bc.oid)
						AND (bc.relname = '" . $db->sql_escape($table_name) . "')
						AND (ta.attrelid = i.indrelid)
						AND (ta.attnum = i.indkey[ia.attnum-1])
					ORDER BY index_name, tab_name, column_name ";
				$result = $db->sql_query($sql_pri_keys);

				$index_create = $index_rows = $primary_key = array();

				// We do this in two steps. It makes placing the comma easier
				while ($row = $db->sql_fetchrow($result))
				{
					if ($row['primary_key'] == 't')
					{
						$primary_key[] = $row['column_name'];
						$primary_key_name = $row['index_name'];
					}
					else
					{
						// We have to store this all this info because it is possible to have a multi-column key...
						// we can loop through it again and build the statement
						$index_rows[$row['index_name']]['table'] = $table_name;
						$index_rows[$row['index_name']]['unique'] = ($row['unique_key'] == 't') ? true : false;
						$index_rows[$row['index_name']]['column_names'][] = $row['column_name'];
					}
				}
				$db->sql_freeresult($result);

				if (!empty($index_rows))
				{
					foreach ($index_rows as $idx_name => $props)
					{
						$index_create[] = 'CREATE ' . ($props['unique'] ? 'UNIQUE ' : '') . "INDEX $idx_name ON $table_name (" . implode(', ', $props['column_names']) . ");";
					}
				}

				if (!empty($primary_key))
				{
					$lines[] = "  CONSTRAINT $primary_key_name PRIMARY KEY (" . implode(', ', $primary_key) . ")";
				}

				// Generate constraint clauses for CHECK constraints
				$sql_checks = "SELECT conname as index_name, consrc
					FROM pg_constraint, pg_class bc
					WHERE conrelid = bc.oid
						AND bc.relname = '" . $db->sql_escape($table_name) . "'
						AND NOT EXISTS (
							SELECT *
								FROM pg_constraint as c, pg_inherits as i
								WHERE i.inhrelid = pg_constraint.conrelid
									AND c.conname = pg_constraint.conname
									AND c.consrc = pg_constraint.consrc
									AND c.conrelid = i.inhparent
						)";
				$result = $db->sql_query($sql_checks);

				// Add the constraints to the sql file.
				while ($row = $db->sql_fetchrow($result))
				{
					if (!is_null($row['consrc']))
					{
						$lines[] = '  CONSTRAINT ' . $row['index_name'] . ' CHECK ' . $row['consrc'];
					}
				}
				$db->sql_freeresult($result);

				$sql_data .= implode(", \n", $lines);
				$sql_data .= "\n);\n";

				if (!empty($index_create))
				{
					$sql_data .= implode("\n", $index_create) . "\n\n";
				}
			break;

			case 'mssql':
			case 'mssql_odbc':
				$sql_data .= "\nCREATE TABLE [$table_name] (\n";
				$rows = array();

				$text_flag = false;

				$sql = "SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLUMNPROPERTY(object_id(TABLE_NAME), COLUMN_NAME, 'IsIdentity') as IS_IDENTITY FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '$table_name'";
				$result = $db->sql_query($sql);

				while ($row = $db->sql_fetchrow($result))
				{
					$line = "\t[{$row['COLUMN_NAME']}] [{$row['DATA_TYPE']}]";

					if ($row['DATA_TYPE'] == 'text')
					{
						$text_flag = true;
					}

					if ($row['IS_IDENTITY'])
					{
						$line .= ' IDENTITY (1 , 1)';
					}

					if ($row['CHARACTER_MAXIMUM_LENGTH'] && $row['DATA_TYPE'] !== 'text')
					{
						$line .= ' (' . $row['CHARACTER_MAXIMUM_LENGTH'] . ')';
					}

					if ($row['IS_NULLABLE'] == 'YES')
					{
						$line .= ' NULL';
					}
					else
					{
						$line .= ' NOT NULL';
					}

					if ($row['COLUMN_DEFAULT'])
					{
						$line .= ' CONSTRAINT [DF_' . $table_name . '_' . $row['COLUMN_NAME'] . '] DEFAULT ' . $row['COLUMN_DEFAULT'];
					}

					$rows[] = $line;
				}
				$db->sql_freeresult($result);

				$sql_data .= implode(",\n", $rows);
				$sql_data .= "\n) ON [PRIMARY]";
				if ($text_flag)
				{
					$sql_data .= " TEXTIMAGE_ON [PRIMARY]";
				}
				$sql_data .= "\nGO\n\n";
				$rows = array();
				$line = '';

				$sql = "SELECT CONSTRAINT_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = '$table_name'";
				$result = $db->sql_query($sql);
				if ($db->sql_numrows($result))
				{
					$sql_data .= "ALTER TABLE [$table_name] WITH NOCHECK ADD\n";
					while ($row = $db->sql_fetchrow($result))
					{
						if (!sizeof($rows))
						{
							$sql_data .= "\tCONSTRAINT [{$row['CONSTRAINT_NAME']}] PRIMARY KEY  CLUSTERED \n\t(\n";
						}
						$rows[] = "\t\t[{$row['COLUMN_NAME']}]";
					}
					$sql_data .= implode(",\n", $rows);
					$sql_data .= "\n\t)  ON [PRIMARY] \nGO\n";
				}
				$db->sql_freeresult($result);
				$rows = array();

				$sql = "EXEC sp_helpindex '$table_name'";
				$result = $db->sql_query($sql);
				if ($db->sql_numrows($result))
				{
					while ($row = $db->sql_fetchrow($result))
					{
						if ($row['index_description'] == 'nonclustered located on PRIMARY')
						{
							$sql_data .= "\nCREATE  INDEX [{$row['index_name']}] ON [$table_name]([{$row['index_keys']}]) ON [PRIMARY]\nGO\n";
						}
					}
				}
				$db->sql_freeresult($result);
			break;

			default:
				trigger_error('KungFuDeathGrip');
		}

		return $sql_data;
	}
}

?>