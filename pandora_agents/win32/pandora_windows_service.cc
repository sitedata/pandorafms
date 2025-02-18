/* Pandora agents service for Win32.
   
   Copyright (c) 2006-2021 Artica ST.
   Written by Esteban Sanchez, Ramon Novoa.
  
   This program is free software; you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation; either version 2, or (at your option)
   any later version.
  
   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.
  
   You should have received a copy of the GNU General Public License along
   with this program; if not, write to the Free Software Foundation,
   Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
*/

#include "pandora_windows_service.h"
#include "pandora.h"
#include "pandora_strutils.h"
#include "windows_service.h"
#include "modules/pandora_module_factory.h"
#include "ssh/pandora_ssh_client.h"
#include "ftp/pandora_ftp_client.h"
#include "misc/pandora_file.h"
#include "misc/sha256.h"
#include "windows/pandora_windows_info.h"
#include "udp_server/udp_server.h"

#include <iostream>
#include <cstdlib>
#include <ctime>
#include <direct.h>
#include <sys/types.h>
#include <dirent.h>
#include <sys/stat.h>
#include <pandora_agent_conf.h>
#include <fstream>
#include <unistd.h>
#include <sstream>
#include <string>

#define BUFSIZE 4096

using namespace std;
using namespace Pandora;
using namespace Pandora_File;
using namespace Pandora_Modules;
using namespace Pandora_Strutils;

Pandora_Windows_Service::Pandora_Windows_Service ()
  : Windows_Service (NULL, NULL, NULL) {
	this->setInitFunction ((void (Windows_Service::*) ())
			       &Pandora_Windows_Service::pandora_init);
	this->setRunFunction ((void (Windows_Service::*) ())
			      &Pandora_Windows_Service::pandora_run);
	this->started = false;
}

/** 
 * Set Pandora service Windows properties.
 * 
 * @param svc_name Internal service name
 * @param svc_display_name Service name that will appear in the
 *        Windows service administration tool.
 * @param svc_description Long description of the service.
 */
void
Pandora_Windows_Service::setValues (const char * svc_name,
				    const char * svc_display_name,
				    const char * svc_description) {
	this->service_name          = (char *) svc_name;
	this->service_display_name  = (char *) svc_display_name;
	this->service_description   = (char *) svc_description;
	execution_number            = 0;
	this->modules               = NULL;
	this->broker_modules		= NULL;
	this->conf                  = NULL;
	this->interval              = 60000;
	this->timestamp             = 0;
	this->run_time              = 0;
	this->udp_server            = NULL;
	this->tentacle_proxy        = false;
	this->intensive_interval    = 60000;
}

/** 
 * Destroys a Pandora_Windows_Service object.
 */
Pandora_Windows_Service::~Pandora_Windows_Service () {
	
	if (this->conf != NULL) {
		if(conf->getValue("proxy_mode") != "") {
			killTentacleProxy();
		}
		delete this->conf;
	}
	
	if (this->udp_server != NULL) {
		((UDP_Server *)udp_server)->stop ();
		delete (UDP_Server *)udp_server;
	}

	if (this->modules != NULL) {
		delete this->modules;
	}

	if (this->broker_modules != NULL) {
		delete this->broker_modules;
	}
	pandoraLog ("Pandora agent stopped");
}

Pandora_Windows_Service *
Pandora_Windows_Service::getInstance () {
	static Pandora_Windows_Service *service = NULL;
	
	if (service != NULL)
		return service;
	
	service = new Pandora_Windows_Service ();
	
	return service;
}

void
Pandora_Windows_Service::start () {
	this->started = true;
}

void
Pandora_Windows_Service::pandora_init_broker (string file_conf) {
	string interval, debug, transfer_interval, util_dir, path, env, name_agent;
	string udp_server_enabled, udp_server_port, udp_server_addr, udp_server_auth_addr;
	int pos;

	name_agent = "PANDORA_AGENT=" + checkAgentName(file_conf);
	putenv(name_agent.c_str());
	
	this->conf = Pandora::Pandora_Agent_Conf::getInstance ();
	this->conf->setFile (file_conf);
	if (this->broker_modules != NULL) {
		delete this->broker_modules;
	}
	this->broker_modules = new Pandora_Module_List (file_conf);
	
	pandoraDebug ("Pandora broker agent started");
}

int
Pandora_Windows_Service::count_broker_agents(){
	string       buffer;
	string       filename;
	int pos;
	int 		 num = 0;
	
	filename = Pandora::getPandoraInstallDir ();
	filename += "pandora_agent.conf";
	ifstream     file (filename.c_str ());
	
	/* Read and set the file */
	while (!file.eof ()) {
		/* Set the value from each line */
		getline (file, buffer);
		
		/* Ignore blank or commented lines */
		if (buffer[0] != '#' && buffer[0] != '\n' && buffer[0] != '\0') {
				/*Check if is a broker_agent*/
				pos = buffer.find("broker_agent");
				if (pos != string::npos){
					num += 1;
				}
		}
	}
	file.close ();
	return num;
}

void
Pandora_Windows_Service::check_broker_agents(string *all_conf){
	string       buffer, filename;
	int pos;
	int pos_file = 0;
	
	filename = Pandora::getPandoraInstallDir ();
	filename += "pandora_agent.conf";

	ifstream     file (filename.c_str ());
	
		while (!file.eof ()) {
		/* Set the value from each line */
			getline (file, buffer);
		
		/* Ignore blank or commented lines */
			if (buffer[0] != '#' && buffer[0] != '\n' && buffer[0] != '\0') {
				/*Check if is a broker_agent*/
				pos = buffer.find("broker_agent");
					if (pos != string::npos){
						string name_broker, path_broker;
						
						name_broker = buffer.substr(pos+13);
						path_broker = Pandora::getPandoraInstallDir () + name_broker + ".conf";
						all_conf[pos_file] = path_broker;
						pos_file += 1;
					}
			}
		}
	file.close();
}


void
Pandora_Windows_Service::pandora_init () {
	pandora_init(true);
}

void
Pandora_Windows_Service::pandora_init (bool reload_modules) {
	string conf_file, interval, debug, disable_logfile, intensive_interval, util_dir, path, env;
	string udp_server_enabled, udp_server_port, udp_server_addr, udp_server_auth_addr;
	string agent_name, agent_name_cmd, agent_alias, agent_alias_cmd, pandora_agent;
	string proxy_mode, server_ip;
	string *all_conf;
	int pos, num;
	static unsigned char first_run = 1;
                
	conf_file = Pandora::getPandoraInstallDir ();
	conf_file += "pandora_agent.conf";

	num = count_broker_agents();
	all_conf = new string[num];
	
	this->conf = Pandora::Pandora_Agent_Conf::getInstance ();
	this->conf->setFile (all_conf);
	if (this->modules != NULL && reload_modules) {
		delete this->modules;
	}

	/* Get the interval value (in seconds) and set it to the service */
	interval = conf->getValue ("interval");
	intensive_interval = conf->getValue ("intensive_interval");

	if (interval != "") {
		try {
			/* miliseconds */
			this->interval_sec = strtoint (interval);
			this->interval = this->interval_sec * 1000;
		} catch (Invalid_Conversion e) {
		}
	}

	/* Set up the secondary buffer. */
	if (conf->getValue ("secondary_mode") == "always") {
		string secondary_temporal = conf->getValue("temporal");
		if (secondary_temporal[secondary_temporal.length () - 1] != '\\') {
			secondary_temporal += "\\";
		}
		secondary_temporal += SECONDARY_DIR;
		if (!dirExists(secondary_temporal) && mkdir (secondary_temporal.c_str()) != 0) {
			pandoraLog ("Pandora_Windows_Service::pandora_init: Can not create directory %s", secondary_temporal.c_str());
		}
		conf->setValue("secondary_temporal", secondary_temporal);
	}
	else if (conf->getValue ("secondary_mode") == "on_error") {
		conf->setValue("secondary_temporal", conf->getValue("temporal"));
	}
	
	// Set the intensive interval
	if (intensive_interval != "") {
		try {
			/* miliseconds */
			this->intensive_interval = strtoint (intensive_interval) * 1000;
		} catch (Invalid_Conversion e) {
		}
	} else {
		this->intensive_interval = this->interval;
	}
		
	this->setSleepTime (this->intensive_interval);

	// Read modules
	if (reload_modules) {
		this->modules = new Pandora_Module_List (conf_file);
	}
	delete []all_conf;
    
    // Get the agent alias.
	agent_alias = conf->getValue ("agent_alias");
	if (agent_alias == "") {
        agent_alias_cmd = conf->getValue ("agent_alias_cmd");
        if (agent_alias_cmd != "") {
            agent_alias_cmd = "cmd.exe /c \"" + agent_alias_cmd + "\"";
			static string temp_agent_alias = getAgentNameFromCmdExec(agent_alias_cmd);

			// Delete new line and carriage return.
			pos = temp_agent_alias.find("\n");
			if(pos != string::npos) {
				temp_agent_alias.erase(pos, temp_agent_alias.size () - pos);
			}
			pos = temp_agent_alias.find("\r");
			if(pos != string::npos) {
				temp_agent_alias.erase(pos, temp_agent_alias.size () - pos);
			}

			// Remove leading and trailing white spaces.
			temp_agent_alias = trim(temp_agent_alias);
			if (temp_agent_alias != "") {
				agent_alias = temp_agent_alias;
            }
        } else {
            agent_alias = Pandora_Windows_Info::getSystemName ();
        }
	}
	this->conf->setValue("agent_alias", agent_alias);
    
	// Get the agent name.
	agent_name = conf->getValue ("agent_name");
	if (agent_name == "") {
		agent_name_cmd = conf->getValue ("agent_name_cmd");

		// Random name.
		if (agent_name_cmd == "__rand__") {
			agent_name = generateAgentName();
			this->conf->setValue("agent_name", agent_name);
			conf->updateFile("agent_name", agent_name); // Write random names to disk!
		}
		// Name from command.
		else if (agent_name_cmd != "") {
			agent_name_cmd = "cmd.exe /c \"" + agent_name_cmd + "\"";
			static string temp_agent_name = getAgentNameFromCmdExec(agent_name_cmd);

			// Delete new line and carriage return.
			pos = temp_agent_name.find("\n");
			if(pos != string::npos) {
				temp_agent_name.erase(pos, temp_agent_name.size () - pos);
			}
			pos = temp_agent_name.find("\r");
			if(pos != string::npos) {
				temp_agent_name.erase(pos, temp_agent_name.size () - pos);
			}

			// Remove leading and trailing white spaces.
			temp_agent_name = trim(temp_agent_name);
			if (temp_agent_name != "") {
				agent_name = temp_agent_name;
				this->conf->setValue("agent_name", agent_name);
			}
		}
	}

	// Fall back to the hostname if agent_name is still empty.
	if (agent_name == "") {
		agent_name = Pandora_Windows_Info::getSystemName ();
		this->conf->setValue("agent_name", agent_name);
	}

	pandora_agent = "PANDORA_AGENT=" + agent_name;
	putenv(pandora_agent.c_str());
	
	debug = conf->getValue ("debug");
	setPandoraDebug (is_enabled (debug));
	
	disable_logfile = conf->getValue ("disable_logfile");
	setPandoraLogDisable (is_enabled(disable_logfile));
		
	if (first_run == 1) {
		first_run = 0;

		// Add the util subdirectory to the PATH
		util_dir = Pandora::getPandoraInstallDir ();
		util_dir += "util";
		path = getenv ("PATH");
		env = "PATH=" + path + ";" + util_dir;
		putenv (env.c_str ());

		// Set the seed for rand
		srand ((unsigned) time (0));

		pandoraLog ("Pandora agent started");
	}

	/*Check if proxy mode is set*/
	proxy_mode = conf->getValue ("proxy_mode");
	if (proxy_mode != "" && this->tentacle_proxy == false) {
		launchTentacleProxy();	
	}
	
	/* Launch UDP Server */
	udp_server_enabled = conf->getValue ("udp_server");
	if (udp_server_enabled.compare ("1") == 0 && this->udp_server == NULL) {
		udp_server_port = conf->getValue ("udp_server_port");
		udp_server_addr = conf->getValue ("udp_server_address");
		udp_server_auth_addr = conf->getValue ("udp_server_auth_address");
		this->udp_server = new UDP_Server (this, udp_server_addr, udp_server_auth_addr, atoi (udp_server_port.c_str ()));
		((UDP_Server *)this->udp_server)->start ();
	}
}

int
Pandora_Windows_Service::killTentacleProxy() {
	PROCESS_INFORMATION pi;
	STARTUPINFO         si;		
	string kill_cmd;
	
	if (this->tentacle_proxy == false) {
		return 0;
	}
	
	kill_cmd = "taskkill.exe /F /IM tentacle_server.exe";
	
	ZeroMemory (&si, sizeof (si));
	ZeroMemory (&pi, sizeof (pi));
	if (CreateProcess (NULL , (CHAR *)kill_cmd.c_str (), NULL, NULL, FALSE,
		CREATE_NO_WINDOW, NULL, NULL, &si, &pi) == 0) {
		return -1;
	}
		
	this->tentacle_proxy = false;
}

int 
Pandora_Windows_Service::launchTentacleProxy() {
	string server_ip, server_port, proxy_max_connections, proxy_timeout, server_ssl;
	string proxy_cmd, proxy_address, proxy_port;
	PROCESS_INFORMATION pi;
	STARTUPINFO         si;	
	
	/*Check if server proxy is localhost*/
	server_ip = conf->getValue("server_ip");
	
	if (server_ip != "localhost") {

		proxy_max_connections = conf->getValue("proxy_max_connection");

		if (proxy_max_connections == "") {
			proxy_max_connections = "10";
		}
		
		proxy_timeout = conf->getValue("proxy_timeout");
		
		if (proxy_timeout == "") {
			proxy_timeout = "1";
		}
		
		server_port = conf->getValue("server_port");
		
		if (server_port == "") {
			server_port = "41121";
		}

		server_ssl = conf->getValue("server_ssl");

		if (server_ssl == "1") {
			proxy_cmd = "tentacle_server.exe -C";
		}
		else {
			proxy_cmd = "tentacle_server.exe";
		}

		// Proxy address.
		proxy_address = conf->getValue("proxy_address");
		if (proxy_address == "") {
			proxy_address = "0.0.0.0";
		}

		// Proxy port.
		proxy_port = conf->getValue("proxy_port");
		if (proxy_port == "") {
			proxy_port = "41121";
		}

		proxy_cmd += " -b " + server_ip + " -g " + server_port + " -c " + proxy_max_connections + " -t " + proxy_timeout + " -a " + proxy_address + " -p " + proxy_port;

		ZeroMemory (&si, sizeof (si));
		ZeroMemory (&pi, sizeof (pi));
		if (CreateProcess (NULL , (CHAR *)proxy_cmd.c_str (), NULL, NULL, FALSE,
			CREATE_NO_WINDOW, NULL, NULL, &si, &pi) == 0) {
			pandoraLog("There was a problem lauching Tentacle Proxy");
			return -1;
		}
		
		this->tentacle_proxy = true;
		pandoraLog("Proxy mode enabled");				
	} else {
		pandoraLog ("[error] You can not proxy to localhost");
	}	

}

string
Pandora_Windows_Service::getXmlHeader () {
	char          timestamp[20];
	string        agent_name, os_name, os_version, encoding, value, xml, address, parent_agent_name, agent_name_cmd, agent_alias;
	string        custom_id, url_address, latitude, longitude, altitude, position_description, gis_exec, gis_result, agent_mode;
	string        group_password, group_id, ehorus_conf;
	time_t        ctime;
	struct tm     *ctime_tm = NULL;
	int pos;
	
	// Get agent name
	agent_name = conf->getValue ("agent_name");

	// Get agent alias
	agent_alias = conf->getValue ("agent_alias");

	// Get parent agent name
	parent_agent_name = conf->getValue ("parent_agent_name");
	
	// Get timestamp
	ctime = time(0);
	ctime_tm = localtime(&ctime);
	value = conf->getValue ("autotime");
	timestamp[0] = '\0';
	if (value != "1") {
		sprintf (timestamp, "%d-%02d-%02d %02d:%02d:%02d", ctime_tm->tm_year + 1900,
			ctime_tm->tm_mon + 1,	ctime_tm->tm_mday, ctime_tm->tm_hour,
			ctime_tm->tm_min, ctime_tm->tm_sec);
	}
	
	// Get OS name and version
	os_name = Pandora_Windows_Info::getOSName ();
	os_version = os_name + Pandora_Windows_Info::getOSVersion ();

	// Get encoding
	encoding = conf->getValue ("encoding");
	if (encoding == "") {
		encoding = "ISO-8859-1";
	}

	xml = "<?xml version=\"1.0\" encoding=\"" + encoding + "\" ?>\n" +
	      "<agent_data agent_name=\"" + agent_name +
	      "\" agent_alias=\"" + agent_alias +
	      "\" description=\"" + conf->getValue ("description") +
	      "\" version=\"" + getPandoraAgentVersion ();

	/* Skip the timestamp if autotime was enabled */
	if (timestamp[0] != '\0') {
		xml += "\" timestamp=\"";
		xml += timestamp; 
	}
	
	// Get agent address
	address = conf->getValue ("address");
	if (address != "") {
		if(address == "auto") {
			address = Pandora_Windows_Info::getSystemAddress ();
		}
		
		if(address != "") {
			xml += "\" address=\"";
			xml += address;
		}
	}
	
	// Get Custom ID
	custom_id = conf->getValue ("custom_id");
	if (custom_id != "") {
		xml += "\" custom_id=\"";
		xml += custom_id;
	}
	
	// Get Url Address
	url_address = conf->getValue ("url_address");
	if (url_address != "") {
		xml += "\" url_address=\"";
		xml += url_address;
	}

	// Get group password
	group_password = conf->getValue ("group_password");
	if (group_password != "") {
		xml += "\" group_password=\"";
		xml += group_password;
	}

	// Get Group ID
	group_id = conf->getValue ("group_id");
	if (group_id != "") {
		xml += "\" group_id=\"";
		xml += group_id;
	}
	
	// Get Coordinates
	gis_exec = conf->getValue ("gis_exec");
	
	if(gis_exec != "") {
		gis_result = getCoordinatesFromCmdExec(gis_exec);
		if(gis_result != "") {
			// Delete carriage return if is provided
			pos = gis_result.find("\n");
			if(pos != string::npos) {
				gis_result.erase(pos, gis_result.size () - pos);
			}	
			pos = gis_result.find("\r");
			if(pos != string::npos) {
				gis_result.erase(pos, gis_result.size () - pos);
			}

			// Process the result as "latitude,longitude,altitude"
			pandoraDebug ("getCoordinatesFromCmdExec: Parsing coordinates %s", gis_result.c_str ());
			pos = gis_result.find(",");
			if (pos != string::npos && pos != 0) {
				latitude = gis_result;
				gis_result = gis_result.substr(pos+1);	
				latitude.erase(pos, latitude.size () - pos);
				pos = gis_result.find(",");
				if(pos != string::npos && pos != 0) {
					longitude = gis_result;
					altitude = gis_result.substr(pos+1);
					longitude.erase(pos, longitude.size () - pos);
				}
				xml += "\" latitude=\"";
				xml += latitude;
				xml += "\" longitude=\"";
				xml += longitude;
				xml += "\" altitude=\"";
				xml += altitude;
			}	
		}
	}
	else {
		latitude = conf->getValue ("latitude");
		longitude = conf->getValue ("longitude");
		if(latitude != "" && longitude != "") {
			xml += "\" latitude=\"";
			xml += latitude;
			xml += "\" longitude=\"";
			xml += longitude;
			
			altitude = conf->getValue ("altitude");
			if(altitude != "") {
				xml += "\" altitude=\"";
				xml += altitude;
			}
			
			position_description = conf->getValue ("position_description");
			position_description = "";
			if(position_description != "") {
				xml += "\" position_description=\"";
				xml += position_description;
			}
		}
	}
	
	// Get agent mode
	agent_mode = conf->getValue ("agent_mode");	
	// Convert the type string to lowercase
    for (int i = 0; i < agent_mode.length(); i++) {
        agent_mode[i] = tolower(agent_mode[i]);
    }
    if (!agent_mode.compare("no-learn") || !agent_mode.compare("nolearn")) {
		agent_mode = "0";
	} else if (!agent_mode.compare("autodisable")) {
		agent_mode = "2";
	} else {
		agent_mode = "1";
	}

	xml += "\" interval=\"" + conf->getValue ("interval") +
	       "\" os_name=\"" + os_name +
	       "\" os_version=\"" + os_version +
	       "\" group=\"" + conf->getValue ("group") +
	       "\" parent_agent_name=\"" + conf->getValue ("parent_agent_name") + 
	       "\" secondary_groups=\"" + conf->getValue ("secondary_groups") + 
	       "\" agent_mode=\"" + agent_mode + 
	       "\">\n";
	return xml;
}

string
Pandora_Windows_Service::getValueFromCmdExec (string cmd_exec, int timeout)
{
	PROCESS_INFORMATION pi;
	STARTUPINFO         si;	

	DWORD               retval, dwRet;
	SECURITY_ATTRIBUTES attributes;
	HANDLE              out, new_stdout, out_read, job;
	string              working_dir;
	string output = "";
		
	/* Set the bInheritHandle flag so pipe handles are inherited. */
	attributes.nLength = sizeof (SECURITY_ATTRIBUTES); 
	attributes.bInheritHandle = TRUE; 
	attributes.lpSecurityDescriptor = NULL;

	/* Create a job to kill the child tree if it become zombie */
	/* CAUTION: In order to compile this, WINVER should be defined to 0x0500.
	This may need no change, since it was redefined by the 
	program, but if needed, the macro is defined 
	in <windef.h> */

	job = CreateJobObject (&attributes, NULL);

	if (job == NULL) {
		pandoraLog ("getValueFromCmdExec: CreateJobObject failed. Err: %d", GetLastError ());
		return "";
	}

	/* Get the handle to the current STDOUT. */
	out = GetStdHandle (STD_OUTPUT_HANDLE); 

	if (! CreatePipe (&out_read, &new_stdout, &attributes, 0)) {
		pandoraLog ("getValueFromCmdExec: CreatePipe failed. Err: %d", GetLastError ());
		return "";
	}

	/* Ensure the read handle to the pipe for STDOUT is not inherited */
	SetHandleInformation (out_read, HANDLE_FLAG_INHERIT, 0);

	
	ZeroMemory (&si, sizeof (si));
	GetStartupInfo (&si);

	si.cb = sizeof (si);
	si.dwFlags     = STARTF_USESTDHANDLES | STARTF_USESHOWWINDOW;
	si.wShowWindow = SW_HIDE;
	si.hStdError   = new_stdout;
	si.hStdOutput  = new_stdout;

	/* Set up members of the PROCESS_INFORMATION structure. */
	ZeroMemory (&pi, sizeof (pi));
	
	pandoraDebug ("Executing cmd_exec: %s", cmd_exec.c_str ());
	
	/* Create the child process. */
	if (CreateProcess (NULL , (CHAR *)cmd_exec.c_str (), NULL, NULL, TRUE,
		CREATE_SUSPENDED | CREATE_NO_WINDOW, NULL, NULL, &si, &pi) == 0) {
		pandoraLog ("getValueFromCmdExec: %s CreateProcess failed. Err: %d",
		cmd_exec.c_str (), GetLastError ());
		return "";
	} else {
		char          buffer[BUFSIZE + 1];
		unsigned long read, avail;

		if (! AssignProcessToJobObject (job, pi.hProcess)) {
			pandoraLog ("getValueFromCmdExec: could not assign proccess to job (error %d)",
			GetLastError ());
		}
		ResumeThread (pi.hThread);

		/*string output;*/
		int tickbase = GetTickCount();
		while ( (dwRet = WaitForSingleObject (pi.hProcess, 500)) != WAIT_ABANDONED ) {
			PeekNamedPipe (out_read, buffer, BUFSIZE, &read, &avail, NULL);
			if (avail > 0) {
				ReadFile (out_read, buffer, BUFSIZE, &read, NULL);
				buffer[read] = '\0';
				output += (char *) buffer;
			}
	
			if (dwRet == WAIT_OBJECT_0) { 
				break;
			} else if(timeout < GetTickCount() - tickbase) {
				/* STILL_ACTIVE */
				TerminateProcess(pi.hThread, STILL_ACTIVE);
				pandoraLog ("getValueFromCmdExec: %s timed out (retcode: %d)", cmd_exec.c_str (), STILL_ACTIVE);
				break;
			}
		}

		GetExitCodeProcess (pi.hProcess, &retval);

		if (retval != 0) {
			if (! TerminateJobObject (job, 0)) {
				pandoraLog ("getValueFromCmdExec: TerminateJobObject failed. (error %d)",
				GetLastError ());
			}
			if (retval != STILL_ACTIVE) {
				pandoraLog ("getValueFromCmdExec: %s did not executed well (retcode: %d)",
				cmd_exec.c_str (), retval);
			}
			/* Close job, process and thread handles. */
			CloseHandle (job);
			CloseHandle (pi.hProcess);
			CloseHandle (pi.hThread);
			CloseHandle (new_stdout);
			CloseHandle (out_read);
			return "";
		}
	
		/* Close job, process and thread handles. */
		CloseHandle (job);
		CloseHandle (pi.hProcess);
		CloseHandle (pi.hThread);
	}

	CloseHandle (new_stdout);
	CloseHandle (out_read);

	return output;
}

string
Pandora_Windows_Service::getAgentNameFromCmdExec (string cmd_exec)
{
	return getValueFromCmdExec(cmd_exec, 60000);
}

string
Pandora_Windows_Service::getCoordinatesFromCmdExec (string cmd_exec)
{
	return getValueFromCmdExec(cmd_exec, 500);
}

int
Pandora_Windows_Service::copyTentacleDataFile (string host,
					       string filename,
					       string port,
					       string ssl,
					       string pass,
					       string opts,
					       int timeout)
{
	DWORD    rc;
	string  var, filepath;
	string	tentacle_cmd, working_dir;
	PROCESS_INFORMATION pi;
	STARTUPINFO         si;

	var = conf->getValue ("temporal");
	if (var[var.length () - 1] != '\\') {
		var += "\\";
	}

	filepath = var + filename;
	
	/* Build the command to launch the Tentacle client */
	tentacle_cmd = "tentacle_client.exe -a " + host;

	if (port != "") {
		tentacle_cmd += " -p " + port;
	}

	if (ssl == "1") {
		tentacle_cmd += " -c";
	}

	if (pass != "") {
		tentacle_cmd += " -x " + pass;
	}

	if (opts != "") {
		tentacle_cmd += " " + opts;
	}

	tentacle_cmd += " \"" +  filepath + "\"";
	
	/* Copy the file */
	pandoraDebug ("Remote copying XML %s on server %s",
		      filepath.c_str (), host.c_str ());
	pandoraDebug ("Command %s", tentacle_cmd.c_str());

	ZeroMemory (&si, sizeof (si));
	ZeroMemory (&pi, sizeof (pi));
	if (CreateProcess (NULL , (CHAR *)tentacle_cmd.c_str (), NULL, NULL, FALSE,
	    CREATE_NO_WINDOW, NULL, NULL, &si, &pi) == 0) {
		return -1;
	}

	/* close thread handle, because it won't be used */
	CloseHandle (pi.hThread);
	
	/* Timeout */
	if (timeout <= 0) {
		timeout = 30;
 	}
	/* Convert to milliseconds */
	timeout *= 1000;

	if (WaitForSingleObject(pi.hProcess, timeout) == WAIT_TIMEOUT) {
		TerminateProcess(pi.hProcess, STILL_ACTIVE);
		CloseHandle (pi.hProcess);
		pandoraLog ("Pandora Agent: Failed when copying to %s (tentacle timeout)", host.c_str ());
		return -1;
	}

	/* Get the return code of the tentacle client*/	
	GetExitCodeProcess (pi.hProcess, &rc);
	if (rc != 0) {
		CloseHandle (pi.hProcess);
		pandoraLog ("Pandora Agent: Failed when copying to %s",
			    host.c_str ());
		return -1;
	}

	CloseHandle (pi.hProcess);
	return 0;
}

int
Pandora_Windows_Service::copyScpDataFile (string host,
					  string remote_path,
					  string filename)
{
	/* TODO: timeout implementation */

	int rc = 0;
	SSH::Pandora_Ssh_Client ssh_client;
	string                  tmp_dir, filepath,port_str;
	string                  pubkey_file, privkey_file;
	int port;

	tmp_dir = conf->getValue ("temporal");
	if (tmp_dir[tmp_dir.length () - 1] != '\\') {
		tmp_dir += "\\";
	}
	filepath = tmp_dir + filename;

	pandoraDebug ("Connecting with %s", host.c_str ());

	pubkey_file   = Pandora::getPandoraInstallDir ();
	pubkey_file  += "key\\id_dsa.pub";
	privkey_file  = Pandora::getPandoraInstallDir ();
	privkey_file += "key\\id_dsa";
	
	port_str = conf->getValue ("server_port");
	if (port_str.length () == 0) {
		port = SSH_DEFAULT_PORT;
	} else {
		port = strtoint(port_str);
	}

	rc = ssh_client.connectWithPublicKey (host.c_str (), port, "pandora",
						 pubkey_file, privkey_file, "");
	if (rc == AUTHENTICATION_FAILED) {
		pandoraLog ("Pandora Agent: Authentication Failed "
			    "when connecting to %s",
			    host.c_str ());
		return rc;
	} else if (rc == PANDORA_EXCEPTION) {
		pandoraLog ("Pandora Agent: Failed when copying to %s",
			    host.c_str ());
		return rc;
	}

	pandoraDebug ("Remote copying XML %s on server %s at %s%s",
		      filepath.c_str (), host.c_str (),
		      remote_path.c_str (), filename.c_str ());
	
	rc = ssh_client.scpFileFilename (remote_path + filename,
					    filepath);
	if (rc == PANDORA_EXCEPTION) {
		pandoraLog ("Unable to copy at %s%s", remote_path.c_str (),
			    filename.c_str ());
		ssh_client.disconnect();
		return rc;
	}

	ssh_client.disconnect();
	return rc;
}

int
Pandora_Windows_Service::copyFtpDataFile (string host,
					  string remote_path,
					  string filename,
					  string password,
					  int timeout)
{
	int rc = 0;
	FTP::Pandora_Ftp_Client ftp_client;
	string                  filepath, port_str;
	int port;

	filepath = conf->getValue ("temporal");
	if (filepath[filepath.length () - 1] != '\\') {
		filepath += "\\";
	}
	filepath += filename;

	port_str = conf->getValue ("server_port");
	if (port_str.length () == 0) {
		port = FTP_DEFAULT_PORT;
	} else {
		port = strtoint(port_str);
	}

	ftp_client.connect (host,
			    port,
			    "pandora",
			    password);

	rc = ftp_client.ftpFileFilename (remote_path + filename,
					 filepath, timeout);
	if (rc == UNKNOWN_HOST) {
		pandoraLog ("Pandora Agent: Failed when copying to %s (%s)",
			    host.c_str (), ftp_client.getError ().c_str ());
		ftp_client.disconnect ();
		return rc;
	} else if (rc == AUTHENTICATION_FAILED) {
		pandoraLog ("Pandora Agent: Authentication Failed "
			    "when connecting to %s (%s)",
			    host.c_str (), ftp_client.getError ().c_str ());
		ftp_client.disconnect ();
		return rc;
	} else if (rc == FTP_EXCEPTION) {
		pandoraLog ("Pandora Agent: Failed when copying to %s (%s)",
			    host.c_str (), ftp_client.getError ().c_str ());
		ftp_client.disconnect ();
		return rc;
	}

	ftp_client.disconnect ();
	return rc;
}

int
Pandora_Windows_Service::copyDataFile (string filename, bool secondary_buffer)
{
	int rc = 0, timeout;
	unsigned char copy_to_secondary = 0;
	string mode, host, remote_path;

	mode = conf->getValue ("transfer_mode");
	host = conf->getValue ("server_ip");
	remote_path = conf->getValue ("server_path");
	timeout = atoi (conf->getValue ("transfer_timeout").c_str ());
	if (timeout == 0) {
		timeout = 30;
	}

	// Fix remote path
	if (mode != "local" && remote_path[remote_path.length () - 1] != '/') {
		remote_path += "/";
	} else if (mode == "local" && remote_path[remote_path.length () - 1] != '\\') {
		remote_path += "\\";
	}

	if (mode == "ftp") {
		rc = copyFtpDataFile (host, remote_path, filename, conf->getValue ("server_pwd"), timeout);
	} else if (mode == "tentacle" || mode == "") {
		rc = copyTentacleDataFile (host, filename, conf->getValue ("server_port"),
			                      conf->getValue ("server_ssl"), conf->getValue ("server_pwd"),
					   conf->getValue ("server_opts"), timeout);
	} else if (mode == "ssh") {
		rc =copyScpDataFile (host, remote_path, filename);
	} else if (mode == "local") {
		rc = copyLocalDataFile (remote_path, filename);
	} else {
		rc = PANDORA_EXCEPTION;
		pandoraLog ("Invalid transfer mode: %s."
			    "Please recheck transfer_mode option "
			    "in configuration file.");
	}

	if (rc == 0) {
		pandoraDebug ("Successfuly copied XML file to server.");
	}
	
	return rc;
}

int
Pandora_Windows_Service::copyToSecondary (string filename, bool secondary_buffer)
{
	int rc = 0, timeout;
	unsigned char copy_to_secondary = 0;
	string mode, host, remote_path;

	// Read secondary server configuration
	mode = conf->getValue ("secondary_transfer_mode");
	host = conf->getValue ("secondary_server_ip");
	remote_path = conf->getValue ("secondary_server_path");
	timeout = atoi (conf->getValue ("secondary_transfer_timeout").c_str ());
	if (timeout == 0) {
		timeout = 30;
	}

	// Adjust the path for the secondary buffer.
	if (secondary_buffer) {
		filename.insert(0, "\\");
		filename.insert(0, SECONDARY_DIR);
	}

	// Fix remote path
	if (mode != "local" && remote_path[remote_path.length () - 1] != '/') {
		remote_path += "/";
	} else if (mode == "local" && remote_path[remote_path.length () - 1] != '\\') {
		remote_path += "\\";
	}

	// Send the file to the secondary server
	if (mode == "ftp") {
		rc = copyFtpDataFile (host, remote_path, filename, conf->getValue ("secondary_server_pwd"), timeout);
	} else if (mode == "tentacle" || mode == "") {
		rc = copyTentacleDataFile (host, filename, conf->getValue ("secondary_server_port"),
			                      conf->getValue ("secondary_server_ssl"), conf->getValue ("secondary_server_pwd"),
					   conf->getValue ("secondary_server_opts"), timeout);
	} else if (mode == "ssh") {
		rc = copyScpDataFile (host, remote_path, filename);
	} else {
		rc = PANDORA_EXCEPTION;
		pandoraLog ("Invalid transfer mode: %s."
			    "Please recheck secondary_transfer_mode option "
			    "in configuration file.");
	}
	
	if (rc == 0) {
		pandoraDebug ("Successfuly copied XML file to secondary server.");
	}
	
	return rc;
}

void
Pandora_Windows_Service::recvTentacleDataFile (string host,
					       string filename,
					       int timeout)
{
	DWORD	rc;
	string  var;
	string	tentacle_cmd;
	PROCESS_INFORMATION pi;
	STARTUPINFO         si;

	/* Change directory to "temporal" */
	var = conf->getValue ("temporal");
	if (_chdir(var.c_str()) != 0) {
		pandoraDebug ("Error changing directory to %s", var.c_str());
		throw Pandora_Exception ();
	}

	/* Build the command to launch the Tentacle client */
	tentacle_cmd = "tentacle_client.exe -g -a " + host;

	var = conf->getValue ("server_port");
	if (var != "") {
		tentacle_cmd += " -p " + var;
	}

	var = conf->getValue ("server_ssl");	
	if (var == "1") {
		tentacle_cmd += " -c";
	}

	var = conf->getValue ("server_pwd");
	if (var != "") {
		tentacle_cmd += " -x " + var;
	}

	var = conf->getValue ("server_opts");
	if (var != "") {
		tentacle_cmd += " " + var;
	}

	tentacle_cmd += " " +  filename;

	/* Copy the file */
	pandoraDebug ("Requesting file %s from server %s",
		      filename.c_str (), host.c_str ());
	pandoraDebug ("Command %s", tentacle_cmd.c_str());

	ZeroMemory (&si, sizeof (si));
	ZeroMemory (&pi, sizeof (pi));
	if (CreateProcess (NULL , (CHAR *)tentacle_cmd.c_str (), NULL, NULL, FALSE,
			   CREATE_NO_WINDOW, NULL, NULL, &si, &pi) == 0) {
		throw Pandora_Exception ();
	}

	/* close thread handle, because it won't be used */
	CloseHandle (pi.hThread);

	/* Timeout */
	if (timeout <= 0) {
		timeout = 30;
	}
	/* Convert to milliseconds */
	timeout *= 1000;

	if (WaitForSingleObject(pi.hProcess, timeout) == WAIT_TIMEOUT) {
		TerminateProcess(pi.hProcess, STILL_ACTIVE);
		CloseHandle (pi.hProcess);
		pandoraLog ("Unable to receive file %s (tentacle timeout)", filename.c_str ());
		throw Pandora_Exception ();
	}

	/* Get the return code of the tentacle client*/
	GetExitCodeProcess (pi.hProcess, &rc);
	if (rc != 0) {
		CloseHandle (pi.hProcess);
		pandoraLog ("Unable to receive file %s", filename.c_str ());
		throw Pandora_Exception ();
	}

	CloseHandle (pi.hProcess);
	return;
}

void
Pandora_Windows_Service::recvDataFile (string filename) {
	string mode, host, remote_path;
	int timeout;

	mode = conf->getValue ("transfer_mode");
	host = conf->getValue ("server_ip");
	remote_path = conf->getValue ("server_path");
	timeout = atoi (conf->getValue ("transfer_timeout").c_str ());
	if (remote_path[remote_path.length () - 1] != '/') {
		remote_path += "/";
	}

	try {
		if (mode == "tentacle") {
			recvTentacleDataFile (host, filename, timeout);
		} else {
			pandoraLog ("Transfer mode %s does not support file retrieval.", mode.c_str () );
			throw Pandora_Exception ();
		}
	}
	catch (Pandora_Exception e) {
		throw e;
	}
}

int
Pandora_Windows_Service::copyLocalDataFile (string remote_path,
					  string filename)
{
	string local_path, local_file, remote_file;
	local_path = conf->getValue ("temporal");
	if (local_path[local_path.length () - 1] != '\\') {
		local_path += "\\";
	}

	local_file = local_path + filename;
	remote_file = remote_path + filename;
	if (!CopyFile (local_file.c_str (), remote_file.c_str (), TRUE)) {
        return PANDORA_EXCEPTION;
    }
}

int
Pandora_Windows_Service::unzipCollection(string zip_path, string dest_dir) {
	string	unzip_cmd, dest_cmd;
	PROCESS_INFORMATION pi;
	STARTUPINFO         si;
	mode_t mode;
	DWORD rc;
	
	/*Delete dest directory*/
	Pandora_File::removeDir(dest_dir);

	/* Build the command to create destination diectory*/
	rc = mkdir (dest_dir.c_str());
	
	if (rc != 0) {
		pandoraLog ("Pandora_Windows_Service::unzipCollection: Can not create dir %s", dest_dir.c_str());
		return -1;
	}
	
	/* Build the command to launch the Tentacle client */
	unzip_cmd = "unzip.exe \"" + zip_path + "\" -d \"" + dest_dir + "\"";
	
	ZeroMemory (&si, sizeof (si));
	ZeroMemory (&pi, sizeof (pi));
	if (CreateProcess (NULL , (CHAR *)unzip_cmd.c_str (), NULL, NULL, FALSE,
	    CREATE_NO_WINDOW, NULL, NULL, &si, &pi) == 0) {
		return -1;
	}

	/* Get the return code of the tentacle client*/
    WaitForSingleObject(pi.hProcess, INFINITE);
    GetExitCodeProcess (pi.hProcess, &rc);
    
	if (rc != 0) {
		CloseHandle (pi.hProcess);
		pandoraLog ("Pandora_Windows_Service::unzipCollection: Can not unzip file %s", zip_path.c_str());
		return -1;
	}

	CloseHandle (pi.hProcess);
	return 0;	
}
/*
 * Check the disk for collections installed
 */

void
Pandora_Windows_Service::purgeDiskCollections () {
	
	DIR *dir;
	struct dirent *dir_content;
	struct stat file;
	string tmp, filepath;
	
	/*Do not delete collections if there is a broker agent*/
	if (conf->isBrokerEnabled()) {
		return;
	}	

	filepath = Pandora::getPandoraInstallDir() +"collections\\";
	/*Open the directory*/
	dir = opendir (filepath.c_str ());

	/*Read the directory looking for files and folders*/
	dir_content = readdir(dir);
	
	while (dir_content != NULL) {
				
		stat(tmp.c_str(),&file);
		
		/*If is a folder, check for . and .. */
		if ( (strcmp(dir_content->d_name,".") != 0) && (strcmp(dir_content->d_name,"..") != 0) ) {
			/*If the file is not in collection list, delete the file*/
			if(! conf->isInCollectionList(dir_content->d_name) ) {
				tmp = filepath+dir_content->d_name;
				Pandora_File::removeDir(tmp);
			}
		}

		/*Next item*/
		dir_content = readdir(dir);	
	}
	
	/*Close dir oppened*/
	closedir(dir);	
}

/*
 * Check collections to sync it between server and agent
 */
void
Pandora_Windows_Service::checkCollections () {
	
	int flag, i;
	char *coll_md5 = NULL, *server_coll_md5 = NULL;
	string collection_name, collections_dir, collection_path, collection_md5, tmp;
	string collection_zip, install_dir, temp_dir, dest_dir, path, env;

	/*Get collections directory*/
	install_dir = Pandora::getPandoraInstallDir ();
	collections_dir = install_dir+"collections\\";
	
	/* Get temporal directory */
	temp_dir = conf->getValue ("temporal");
	if (temp_dir[temp_dir.length () - 1] != '\\') {
		temp_dir += "\\";
	}

	/*Set iterator in the firs collection*/
	conf->goFirstCollection();

	while (! conf->isLastCollection()) {
		
		collection_name = conf->getCurrentCollectionName();	

		if(! conf->getCurrentCollectionVerify() ) {	
			int found;

			/*Add the collection directory to the path (if not exists in %path%)*/
			collection_path = collections_dir + collection_name;
			path = getenv ("PATH");

			/* check if the path is just included in the middle of %path% */
			tmp = collection_path + ";";	/* Added a separator */
			if(path.find(tmp) == string::npos) {

				/* check if the path is the last entry of %path% */
				if( ((found = path.rfind(collection_path)) != string::npos)
				   && ((found + collection_path.length()) == path.length()) )
				{
					/* included already (at the tail of %path%) */
					;
				}
				else {
					/* it's new ! */
					env = "PATH=" + path + ";" + collection_path;
					putenv (env.c_str ());
				}
			}

			conf->setCurrentCollectionVerify();
		}
		
		collection_zip = collection_name+".zip";
		collection_md5 = collection_name + ".md5";
		tmp = collections_dir+collection_md5;
			
		/*Reading local collection md5*/
		try {
			if (Pandora_File::readBinFile (tmp, &coll_md5) < 32) {
				pandoraDebug ("Pandora_Windows_Service::checkCollection: Invalid local md5", tmp.c_str());
				if (coll_md5 != NULL) {
					delete[] coll_md5;
				}		
				/*Go to next collection*/		
				conf->goNextCollection();
				continue;
			}
		} catch (...) {
			/*Getting new md5*/
			try {				
				/*Downloading md5 file*/
				recvDataFile (collection_md5);
				
				/*Reading new md5 file*/
				tmp = temp_dir + collection_md5;
				
				if (Pandora_File::readBinFile (tmp, &coll_md5) < 32) {
					pandoraDebug ("Pandora_Windows_Service::checkCollection: Invalid remote md5", tmp.c_str());
					if (coll_md5 != NULL) {
						delete[] coll_md5;
					}
							
					Pandora_File::removeFile (tmp);
					/*Go to next collection*/		
					conf->goNextCollection();
					continue;
				}
				
				Pandora_File::removeFile (tmp);
				
				/* Save new md5 file */
				tmp = collections_dir + collection_md5;
				Pandora_File::writeBinFile (tmp, coll_md5, 32);
				
			} catch(...) {
				pandoraDebug ("Pandora_Windows_Service::checkCollection: Can not download %s", collection_md5.c_str());
				/*Go to next collection*/		
				conf->goNextCollection();
				continue;
			}
			
			/*Getting new zipped collection*/
			try {
				/*Downloading zipped collection*/
				recvDataFile (collection_zip);
				
				/*Uncompress zipped collection*/
				tmp = temp_dir + collection_zip;
				dest_dir = collections_dir + collection_name;
				
				try {
					unzipCollection(tmp,dest_dir);
				} catch (...) {
					Pandora_File::removeFile (tmp);	
					/*Go to next collection*/		
					conf->goNextCollection();
					continue;					
				}
				
				Pandora_File::removeFile (tmp);	
			} catch (...) {
				pandoraDebug ("Pandora_Windows_Service::checkCollection: Can not download %s", collection_zip.c_str());
								
				/*Go to next collection*/		
				conf->goNextCollection();
				continue;
			}
			
			conf->goNextCollection();		
			continue;
		}
		
		/*Reading server collection md5*/
		try {
			
			recvDataFile(collection_md5);
			tmp = temp_dir+collection_md5;
			if (Pandora_File::readBinFile (tmp, &server_coll_md5) < 32) {
				pandoraDebug ("Pandora_Windows_Service::checkCollection: Invalid remote md5", tmp.c_str());
				if (server_coll_md5 != NULL) {
					delete[] server_coll_md5;
				}		
				Pandora_File::removeFile (tmp);	
				/*Go to next collection*/		
				conf->goNextCollection();
				continue;		
			}
			Pandora_File::removeFile (tmp);	
			
		} catch (...) {
			pandoraDebug ("Pandora_Windows_Service::checkCollection: Can not download %s", collection_md5.c_str());
			/*Go to next collection*/		
			conf->goNextCollection();
			continue;		
		}
		
		/*Check both md5*/
		flag = 0;
		for (i = 0; i < 32; i++) {
			if (coll_md5[i] != server_coll_md5[i]) {
				flag = 1;
				break;
			}
		}
		
		/*If the two md5 are equals, exit*/
		if (flag == 0) {
			/*Go to next collection*/		
			conf->goNextCollection();
			continue;
		}
		
		pandoraDebug ("Pandora_Windows_Service::checkCollections: Collection %s has changed", collection_md5.c_str ());
					
		/*Getting new zipped collection*/
		try {
			/*Downloading zipped collection*/
			recvDataFile (collection_zip);
			
			/*Uncompress zipped collection*/
			tmp = temp_dir + collection_zip;
			dest_dir = collections_dir + collection_name;
			
			try {
				unzipCollection(tmp,dest_dir);
			} catch (...) {
				Pandora_File::removeFile (tmp);	
				/*Go to next collection*/		
				conf->goNextCollection();
				continue;					
			}
			
			Pandora_File::removeFile (tmp);	
				
		} catch (...) {
			pandoraDebug ("Pandora_Windows_Service::checkCollection: Can not download %s", collection_zip.c_str());
			
			/*Go to next collection*/		
			conf->goNextCollection();
			continue;	
		}
		
		/* Save new md5 file */
		tmp = collections_dir + collection_md5;
		Pandora_File::writeBinFile (tmp, server_coll_md5, 32);
		
		/*Free coll_md5*/
		if (coll_md5 != NULL) {
			delete[] coll_md5;
		}
		
		/*Free server_coll_md5*/
		if (server_coll_md5 != NULL) {
			delete[] server_coll_md5;
		}
		
		/*Go to next collection*/		
		conf->goNextCollection();
	}
	purgeDiskCollections ();
}

string
Pandora_Windows_Service::checkAgentName(string filename){
	string name_agent = "";
	string       buffer;
	int pos;
	ifstream     file (filename.c_str ());

	while (!file.eof ()) {
		getline (file, buffer);
		/* Ignore blank or commented lines */
		if (buffer[0] != '#' && buffer[0] != '\n' && buffer[0] != '\0') {
			/*Check agent_name*/
			pos = buffer.find("agent_name");
			if (pos != string::npos){
				name_agent = buffer.substr(pos+11);
				return name_agent;
			}
		}
	}
	file.close();
	return name_agent;
}
int
Pandora_Windows_Service::checkConfig (string file) {
	int i, conf_size, pos;
	char *conf_str = NULL, *remote_conf_str = NULL, *remote_conf_md5 = NULL;
	char agent_md5[33], conf_md5[33], flag;
	string agent_name, conf_tmp_file, md5_tmp_file, temp_dir, tmp;

	tmp = conf->getValue ("remote_config");
	if (tmp != "1") {
		pandoraDebug ("Pandora_Windows_Service::checkConfig: Remote configuration disabled");
		return 0;
	}

	/* Get temporal directory */
	temp_dir = conf->getValue ("temporal");
	if (temp_dir[temp_dir.length () - 1] != '\\') {
		temp_dir += "\\";
	}

	/* Get agent name */
	agent_name = conf->getValue ("agent_name");


	/* Error getting agent name */
	if (agent_name.empty ()) {
		pandoraDebug ("Pandora_Windows_Service::checkConfig: Error getting agent name");
		return 0;
	}

	Pandora_File::md5 (agent_name.c_str(), agent_name.size(), agent_md5);

	/* Calculate md5 hashes */
	try {
		conf_size = Pandora_File::readBinFile (file, &conf_str);
		Pandora_File::md5 (conf_str, conf_size, conf_md5);
	} catch (...) {
		pandoraDebug ("Pandora_Windows_Service::checkConfig: Error calculating configuration md5");
		if (conf_str != NULL) {
			delete[] conf_str;
		}
		return 0;
	}

	/* Compose file names from the agent name hash */
	conf_tmp_file = agent_md5;
	conf_tmp_file += ".conf";
	md5_tmp_file = agent_md5;
	md5_tmp_file += ".md5";

	/* Get md5 file from server */
	try {
		recvDataFile (md5_tmp_file);
	} catch (...) {
		/* Not found, upload the configuration */
		try {
			tmp = temp_dir;
			tmp += conf_tmp_file;
			Pandora_File::writeBinFile (tmp, conf_str, conf_size);
			copyDataFile (conf_tmp_file);
			Pandora_File::removeFile (tmp);
		
			tmp = temp_dir;
			tmp += md5_tmp_file;
			Pandora_File::writeBinFile (tmp, conf_md5, 32);
			copyDataFile (md5_tmp_file);
			Pandora_File::removeFile (tmp);
		} catch (...) {
			pandoraDebug ("Pandora_Windows_Service::checkConfig: Error uploading configuration to server");
		}
	
		delete[] conf_str;
		return 0;
	}

	delete[] conf_str;
	conf_str = NULL;

	/* Read remote configuration file md5 */
	try {
		tmp = temp_dir;
		tmp += md5_tmp_file;
		if (Pandora_File::readBinFile (tmp, &remote_conf_md5) < 32) {
			pandoraDebug ("Pandora_Windows_Service::checkConfig: Invalid remote md5", tmp.c_str());
			if (remote_conf_md5 != NULL) {
				delete[] remote_conf_md5;
			}		
			return 0;		   	
		}
		Pandora_File::removeFile (tmp);
	} catch (...) {
		pandoraDebug ("Pandora_Windows_Service::checkConfig: Error checking remote configuration md5", tmp.c_str());
		return 0;
	}

	/* Check for configuration changes */
	flag = 0;
	for (i = 0; i < 32; i++) {
		if (remote_conf_md5[i] != conf_md5[i]) {
			flag = 1;
			break;
		}
	}

	delete[] remote_conf_md5;

	/* Configuration has not changed */
	if (flag == 0) {
		return 0;
	}

	pandoraLog("Pandora_Windows_Service::checkConfig: Configuration for agent %s has changed", agent_name.c_str ());

	/* Get configuration file from server */
	try {
		recvDataFile (conf_tmp_file);
		tmp = temp_dir;
		tmp += conf_tmp_file;
		conf_size = Pandora_File::readBinFile (tmp, &conf_str);
		Pandora_File::removeFile (tmp);
		/* Save new configuration */
		Pandora_File::writeBinFile (file, conf_str, conf_size);
	} catch (...) {
		pandoraDebug("Pandora_Windows_Service::checkConfig: Error retrieving configuration file from server");
		if (conf_str != NULL) {
			delete[] conf_str;
		}
		return 0;
	}

	delete[] conf_str;

	/* Reload configuration */
	return 1;
}

int
Pandora_Windows_Service::sendXml (Pandora_Module_List *modules, string extra /* = ""*/) {
    int rc = 0, rc_sec = 0, xml_buffer;
    string            data_xml;
	string            xml_filename, random_integer;
	string            tmp_filename, tmp_filepath;
	string            secondary_filename, secondary_filepath;
	string            encoding;
	string            ehorus_conf, eh_key;
	static HANDLE     mutex = 0; 
    ULARGE_INTEGER    free_bytes;
    double            min_free_bytes = 0;
	Pandora_Agent_Conf *conf = NULL;
	FILE              *conf_fh = NULL;

	conf = this->getConf ();
	min_free_bytes = 1024 * atoi (conf->getValue ("temporal_min_size").c_str ());
	xml_buffer = atoi (conf->getValue ("xml_buffer").c_str ());
	
	if (mutex == 0) {
		mutex = CreateMutex (NULL, FALSE, NULL);
	}
	
	/* Wait for the mutex to be opened */
	WaitForSingleObject (mutex, INFINITE);
	
	data_xml = getXmlHeader ();
	
	/* Get the eHorus key. */
	ehorus_conf = conf->getValue ("ehorus_conf");
	if (ehorus_conf != "") {
		eh_key = getEHKey(ehorus_conf);
	}
	
	/* Write custom fields */
	int c = 1;
	
	char token_name_token[21]; // enough to hold all numbers up to 64-bits
	char token_value_token[21]; // enough to hold all numbers up to 64-bits
	sprintf(token_name_token, "custom_field%d_name", c);
	sprintf(token_value_token, "custom_field%d_value", c);
	string token_name = conf->getValue (token_name_token);
	string token_value = conf->getValue (token_value_token);

	if((token_name != "" && token_value != "") || eh_key != "") {
		data_xml += "<custom_fields>\n";
		while(token_name != "" && token_value != "") {
			data_xml += "	<field>\n";
			data_xml += "		<name><![CDATA["+ token_name +"]]></name>\n";
			data_xml += "		<value><![CDATA["+ token_value +"]]></value>\n";
			data_xml += "	</field>\n";
			
			c++;
			sprintf(token_name_token, "custom_field%d_name", c);
			sprintf(token_value_token, "custom_field%d_value", c);
			token_name = conf->getValue (token_name_token);
			token_value = conf->getValue (token_value_token);
		}

		/* Add the eHorus key as a custom field. */
		if (eh_key != "") {
			data_xml += "	<field>\n";
			data_xml += "		<name>eHorusID</name>\n";
			data_xml += "		<value><![CDATA["+ eh_key +"]]></value>\n";
			data_xml += "	</field>\n";
		}

		data_xml += "</custom_fields>\n";
	}
	
	/* Write module data */
	if (modules != NULL) {
		modules->goFirst ();
	
		while (! modules->isLast ()) {
			Pandora_Module *module;
			
			module = modules->getCurrentValue ();			
			data_xml += module->getXml ();
			modules->goNext ();
		}
	}

	/* Write extra content (omnishell, for instance) */
	data_xml += extra;

	/* Close the XML header */
	data_xml += "</agent_data>";

	/* Generate temporal filename */
	random_integer = inttostr (rand());
	tmp_filename = conf->getValue ("agent_name");
	tmp_filename += "." + random_integer + ".data";

	xml_filename = conf->getValue ("temporal");
	if (xml_filename[xml_filename.length () - 1] != '\\') {
		xml_filename += "\\";
	}
	tmp_filepath = xml_filename + tmp_filename;

	/* Copy the XML to temporal file */
	pandoraDebug ("Copying XML on %s", tmp_filepath.c_str ());
	conf_fh = fopen (tmp_filepath.c_str (), "wb");
	if (conf_fh == NULL) {
		pandoraLog ("Error when saving the XML in %s",
			    tmp_filepath.c_str ());
		ReleaseMutex (mutex);
		return PANDORA_EXCEPTION;
	}
	fprintf (conf_fh, "%s", data_xml.c_str ());
	fclose (conf_fh);

	/* Allways reports to Data Server*/
	rc = this->copyDataFile (tmp_filename);
	if (rc != 0 && conf->getValue("secondary_mode") == "on_error") {
		rc = this->copyToSecondary (tmp_filename, false);
	} else if (conf->getValue("secondary_mode") == "always") {
		rc_sec = this->copyToSecondary (tmp_filename, false);

		/* Secondary buffer. */
		if (rc_sec != 0 && xml_buffer == 1 && (GetDiskFreeSpaceEx (conf->getValue ("secondary_temporal").c_str (), &free_bytes, NULL, NULL) != 0 && free_bytes.QuadPart >= min_free_bytes)) {
			secondary_filepath = conf->getValue ("secondary_temporal") + "\\" + tmp_filename;
			CopyFile (tmp_filepath.c_str(), secondary_filepath.c_str(), false);
		}
	}
        
	/* Primary buffer. Delete the file if successfully copied, buffer disabled or not enough space available. */
	if (rc == 0 || xml_buffer == 0 || (GetDiskFreeSpaceEx (tmp_filepath.c_str (), &free_bytes, NULL, NULL) != 0 && free_bytes.QuadPart < min_free_bytes)) {
		/* Rename the file if debug mode is enabled*/
		if (getPandoraDebug ()) {
			string tmp_filepath_sent = tmp_filepath;
			tmp_filepath_sent.append("sent");
			CopyFile (tmp_filepath.c_str(), tmp_filepath_sent.c_str(), false);
		}
		Pandora_File::removeFile (tmp_filepath);
	}

	/* Send any buffered data files */
	if (xml_buffer == 1) {
		this->sendBufferedXml (conf->getValue ("temporal"), &Pandora_Windows_Service::copyDataFile, false);
		if (conf->getValue ("secondary_mode") == "always") {
			this->sendBufferedXml (conf->getValue ("secondary_temporal"), &Pandora_Windows_Service::copyToSecondary, true);
		} else {
			this->sendBufferedXml (conf->getValue ("temporal"), &Pandora_Windows_Service::copyToSecondary, false);
		}
	}

	ReleaseMutex (mutex);
}

void
Pandora_Windows_Service::sendBufferedXml (string path, copy_func_p copy_func, bool secondary_buffer) {
    string base_path = path, file_path;
    WIN32_FIND_DATA file_data;
    HANDLE find;

	/* Nothing to do. */
	if (path == "") {
		return;
	}

	if (base_path[base_path.length () - 1] != '\\') {
		base_path += "\\";
	}
    file_path = base_path + "*.data";
    
    /* Search for buffered data files */
    find = FindFirstFile(file_path.c_str (), &file_data);
    if (find == INVALID_HANDLE_VALUE) {
        return;
    }

    /* Send data files as long as there are no errors */
    if ((this->*copy_func) (file_data.cFileName, secondary_buffer) != 0) {
        FindClose(find);
        return;
    }
    
    if (getPandoraDebug ()){
		string file_data_path = base_path + file_data.cFileName;
		string file_data_sent = file_data_path;
		file_data_sent.append("sent");
		CopyFile (file_data_path.c_str(), file_data_sent.c_str(), false);
	}
	
    Pandora_File::removeFile (base_path + file_data.cFileName);

    while (FindNextFile(find, &file_data) != 0) {
        if ((this->*copy_func) (file_data.cFileName, secondary_buffer) != 0) {
            FindClose(find);
            return;
        }
        if (getPandoraDebug ()){
			string file_data_path = base_path + file_data.cFileName;
			string file_data_sent = file_data_path;
			file_data_sent.append("sent");
			CopyFile (file_data_path.c_str(), file_data_sent.c_str(), false);
		}
        Pandora_File::removeFile (base_path + file_data.cFileName);
    }

    FindClose(find);
}

void
Pandora_Windows_Service::pandora_run_broker (string config, long executions) {
	Pandora_Agent_Conf  *conf = NULL;
	string server_addr;
	unsigned char data_flag = 0;
	unsigned char intensive_match;
	
	pandoraDebug ("Run begin");

	conf = this->getConf ();

	/* Check for configuration changes */
	if (getPandoraDebug () == false) {
		if (this->checkConfig (config) == 1) {
			pandora_init_broker (config);
		}
		this->checkCollections ();
	}

	server_addr = conf->getValue ("server_ip");

	if (this->broker_modules != NULL && !(conf->getValue ("standby") == "1" && !getPandoraDebug())) {
		this->broker_modules->goFirst ();
	
		while (! this->broker_modules->isLast ()) {
			Pandora_Module *module;
		
			module = this->broker_modules->getCurrentValue ();

			/* Keep executions matching main agent */
			module->setExecutions(executions);

			/* Check preconditions */
			if (module->evaluatePreconditions () == 0) {
				pandoraDebug ("Preconditions not matched for module %s", module->getName ().c_str ());
				module->setNoOutput ();
				this->broker_modules->goNext ();
				continue;
			}
	
			/* Check cron */			
			if (!module->checkCron (module->getInterval () * atoi (conf->getValue ("interval").c_str()))) {
				pandoraDebug ("Cron not matched for module %s", module->getName ().c_str ());
				module->setNoOutput ();
				this->broker_modules->goNext ();
				continue;
			}
			
			/* Check async */
			if (module->getAsync()) {
				pandoraDebug ("Forbidden async module %s in broker agents", module->getName ().c_str ());
				module->setNoOutput ();
				this->broker_modules->goNext ();
				continue;
			}

			pandoraDebug ("Run %s", module->getName ().c_str ());
			module->run ();
			if (! module->hasOutput ()) {
				module->setNoOutput ();
				this->broker_modules->goNext ();
				continue;
			}
			
			/* Save module data to an environment variable */
			if (!module->getSave().empty ()) {
				module->exportDataOutput ();
			}

			/* Evaluate intensive conditions */
			if (module->isIntensive()) {
				intensive_match = module->evaluateIntensiveConditions ();
				if (intensive_match == module->getIntensiveMatch () && module->getTimestamp () + module->getInterval () * this->interval_sec > this->run_time) {
					module->setNoOutput ();
					this->broker_modules->goNext ();
					continue;
				}

				if (module->getTimestamp () + module->getInterval () * this->interval_sec <= this->run_time) {
					module->setTimestamp (this->run_time);
				}

				module->setIntensiveMatch (intensive_match);
			}
			
			/* Evaluate module conditions */
			module->evaluateConditions ();
			
			/* At least one module has data */
			data_flag = 1;

			this->broker_modules->goNext ();
		}
	}

	if ((data_flag == 1 || this->timestamp + this->interval_sec <= this->run_time) && !(conf->getValue ("standby") == "1" && !getPandoraDebug())) {
		
		// Send the XML
		if (!server_addr.empty ()) {
		  this->sendXml (this->broker_modules);
		}
	}
	
	return;
}

void
Pandora_Windows_Service::pandora_run () {
	pandora_run (0);
}

void
Pandora_Windows_Service::pandora_run (int forced_run) {
	Pandora_Agent_Conf  *conf = NULL;
	string server_addr, conf_file, *all_conf, omnishell_output, omnishell_path;
	int startup_delay = 0;
	int i, num;
	static bool startup = true;
	unsigned char data_flag = 0;
	unsigned char intensive_match;
	
	pandoraDebug ("Run begin");
	
	conf = this->getConf ();
	
	/* process only once at startup */
	if (startup) {
		startup = false;
 	/* Sleep if a startup delay was specified */
 	startup_delay = atoi (conf->getValue ("startup_delay").c_str ()) * 1000;
		if (startup_delay > 0) {
		pandoraLog ("Delaying startup %d miliseconds", startup_delay);
		Sleep (startup_delay);
	}
		setIterationBaseTicks(GetTickCount());
	}

	/* Set the run time */
	this->run_time = time (NULL);
	
	/* Check for configuration changes */
	if (getPandoraDebug () == false) {
		conf_file = Pandora::getPandoraInstallDir ();
		conf_file += "pandora_agent.conf";
		
		if (this->checkConfig (conf_file) == 1) {
			this->pandora_init ();
		}
		this->checkCollections ();
	}

	
	/* Execute omnishell commands */
	omnishell_path = '"'+Pandora::getPandoraInstallDir ();
	omnishell_path += "util\\omnishell_client.exe\" \"" + conf_file+"\"";
	if (getPandoraDebug () != false) {
		pandoraLog ("Omnishell: Running");
	}
	omnishell_output = getValueFromCmdExec(omnishell_path.c_str(), 6000000);

	server_addr = conf->getValue ("server_ip");

	execution_number++;

	if (this->modules != NULL && !(conf->getValue ("standby") == "1" && !getPandoraDebug())) {
		this->modules->goFirst ();
	
		while (! this->modules->isLast ()) {
			Pandora_Module *module;
		
			module = this->modules->getCurrentValue ();
			
			/* Check preconditions */
			if (module->evaluatePreconditions () == 0) {
				pandoraDebug ("Preconditions not matched for module %s", module->getName ().c_str ());
				module->setNoOutput ();
				this->modules->goNext ();
				continue;
			}
	
			/* Check cron */			
			if (!module->checkCron (module->getInterval () * atoi (conf->getValue ("interval").c_str()))) {
				pandoraDebug ("Cron not matched for module %s", module->getName ().c_str ());
				module->setNoOutput ();
				this->modules->goNext ();
				continue;
			}
			
			pandoraDebug ("Run %s", module->getName ().c_str ());
			module->run ();
			if (! module->hasOutput ()) {
				module->setNoOutput ();
				this->modules->goNext ();
				continue;
			}
			
			/* Save module data to an environment variable */
			if (!module->getSave().empty ()) {
				module->exportDataOutput ();
			}

			/* Evaluate intensive conditions */
			if (module->isIntensive()) {
				intensive_match = module->evaluateIntensiveConditions ();
				if (forced_run != 1 && intensive_match == module->getIntensiveMatch () && module->getTimestamp () + module->getInterval () * this->interval_sec > this->run_time) {
					module->setNoOutput ();
					this->modules->goNext ();
					continue;
				}

				if (module->getTimestamp () + module->getInterval () * this->interval_sec <= this->run_time) {
					module->setTimestamp (this->run_time);
				}

				module->setIntensiveMatch (intensive_match);
			}
			
			/* Evaluate module conditions */
			module->evaluateConditions ();
			
			/* At least one module has data */
			data_flag = 1;

			this->modules->goNext ();
		}
	}

	if ((forced_run == 1 || data_flag == 1 || this->timestamp + this->interval_sec <= this->run_time) && !(conf->getValue ("standby") == "1" && !getPandoraDebug())) {
				
		// Send the XML
		if (!server_addr.empty ()) {
		  this->sendXml (this->modules, omnishell_output);
		}
	}
	
	/* Get the interval value (in minutes) */
	pandoraDebug ("Next execution on %d seconds", this->interval_sec);

	/* Load and execute brokers */
	num = count_broker_agents();
	all_conf = new string [num];
	check_broker_agents(all_conf);
	for (i=0;i<num;i++){
		pandora_init_broker(all_conf[i]);
		pandora_run_broker(all_conf[i], execution_number);
	}
	delete []all_conf;
	
	/* Reload the original configuration */
	if (num != 0) {
		pandora_init (false);
	}

	/* Reset time reference if necessary */
	if (this->timestamp + this->interval_sec <= this->run_time) {
		this->timestamp = this->run_time;
	}

	return;
}

Pandora_Agent_Conf  *
Pandora_Windows_Service::getConf () {
	return this->conf;
}

string
Pandora_Windows_Service::getEHKey (string ehorus_conf) {
	string buffer, eh_key;
	std::ifstream ifs(ehorus_conf.c_str());
	int pos;

	if (! ifs.is_open ()) {
		pandoraDebug ("Error opening eHorus configuration file %s", ehorus_conf.c_str ());
		return eh_key;
	}

	/* Look for the eHorus key. */
	while (ifs.good ()) {
		getline (ifs, buffer);

		/* Skip comments. */
		if (buffer.empty() || buffer.at(0) == '#') {
			continue;
		}

		pos = buffer.find("eh_key");
		if (pos != string::npos){
			eh_key = buffer.substr(pos + 7); /* pos + strlen("eh_key ") */
			eh_key = trim(eh_key);
			return eh_key;
		}
	}

	return eh_key;
}

long
Pandora_Windows_Service::getInterval () {
	return this->interval;
}

long
Pandora_Windows_Service::getIntensiveInterval () {
	return this->intensive_interval;
}

string
Pandora_Windows_Service::generateAgentName () {
	stringstream data;
	char digest[SHA256_HEX_LENGTH + 1];

    std::srand(std::time(0));
	data << this->conf->getValue("agent_alias") <<
	        this->conf->getValue("server_ip") <<
		    time(NULL) <<
		    std::rand();

	sha256(data.str().c_str(), digest);
	return std::string(digest);
}
