<!--

function changePort(db_type) {
	var dbport = document.getElementById("dbport");
	
	if (db_type == "mysql") {
		dbport.value = "3306";
	} else {
		dbport.value = "5432";
	}
}

-->

//Add more fields dynamically.
function addField(area,field,limit) {
	if(!document.getElementById) return; //Prevent older browsers from getting any further.
	var field_area = document.getElementById(area);
	var all_inputs = field_area.getElementsByTagName("input"); //Get all the input fields in the given area.
	//Find the count of the last element of the list. It will be in the format '<field><number>'. If the
	//		field given in the argument is 'friend_' the last id will be 'friend_4'.
	var last_item = all_inputs.length - 1;
	var last = all_inputs[last_item].id;
	var count = Number(last.split("_")[1]) + 1;

	//If the maximum number of elements have been reached, exit the function.
	//		If the given limit is lower than 0, infinite number of fields can be created.
	if(count > limit && limit > 0) return;

	if(document.createElement) { //W3C Dom method.
		var li = document.createElement("li");
		var input = document.createElement("input");
		input.id = field+count;
		input.name = "domain[]";
		input.type = "text"; //Type of field - can be any valid input type like text,file,checkbox etc.
		input.className = "input";
		li.appendChild(input);
                var editLink = document.createElement("input");
		editLink.id = 'remove_button';
		editLink.type = 'button';
		editLink.value = 'Remove field';
		editLink.setAttribute('class','button');
                editLink.onclick = function() {this.parentNode.parentNode.removeChild(this.parentNode);}
		li.appendChild(editLink);
		field_area.appendChild(li);
	} else { //Older Method
		field_area.innerHTML += "<li><input name='domain[]' id='"+(field+count)+"' type='text' class='input' /> <a onclick=\"this.parentNode.parentNode.removeChild(this.parentNode);\">Remove Field</a></li>";
	}
}

function getDomainsElements(){

    var
        coll=document.getElementsByTagName('input'),
        re=/^domain\[\]$/,
        t,
        elm,
        i=0,
        key=0,
        records=new Array();

    while(elm=coll.item(i++))
    {
            t=re.exec(elm.name);
            if(t!=null)
              {
                records[key]=elm;
                key++;
              }
    }
    return records;
}

function checkDomainFilled(){
    var
        domains= new Array(),
        allEmpty=true,

    domains=getDomainsElements();
    if (domains.length == 1) {
        if ((domains[0].value.length == 0 || domains[0].value == null || domains[0].value == "")) {
            alert('Zone name cannot be empty');
            return false;
        }
    } else {
        for (key in domains) {
            if((domains[key].value.length != 0)) {
                allEmpty = false;
            }
        }

        if (true === allEmpty) {
          alert('Please fill in at least one Zone name');
          return false;
        }
    }

    add_zone_master.submit();
}
