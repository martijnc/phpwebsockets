/**
 * This file is part of the PHP WebSockets server Demo.
 * You can find the whole project on GitHub:
 * https://github.com/Martijnc
 */

/* Handles most of the chatservers 'subprotocol' */
function ChatServer(serverUrl, chatWindow, userList)
{
    /* The actual websocket connection */
    this.webSocket = null;
    
    /* Contains the WebSockets URL */
    this.serverUrl = serverUrl;
    
    /* This is usefull for protocol version detection when your browser
     * is using an old version of the protocol and calls onClose after the
     * the first connection attempt
     */
    this.connected = false;
    
    /* Points to the chat-window HTMLElement */
    this.chatWindow = chatWindow;
    
    /* Points to to userlist, which is a ul */
    this.userList = userList;

    /* Call this method to connect to the WebSockets server */
    this.connect = function()
    {
        /* See if there is support for WebSockets in your server
         * and whether it is prefixed. */
        if ("WebSocket" in window) /* Chromium uses no prefix */
        {
            this.webSocket = new WebSocket(this.serverUrl);
        }
        else if ("MozWebSocket" in window) /* Mozilla uses the Moz prefix */
        {
            this.webSocket = new MozWebSocket(this.serverUrl);
        }
        else /* Or there is just no WebSockets support.... */
        {
            this.addChatMessage('notice', '* Welcome to the PHP WebSockets server demo.<br>Your browser has no WebSockets support. You can try a Chromium or Firefox nightly for free!');
            return false;
        }
        
        /* Bind events to the methods that will handle them, keeping intact the original
         'this. */
        this.webSocket.onopen = this.bind(this, this.onOpen);
        this.webSocket.onclose = this.bind(this, this.onClose);
        this.webSocket.onmessage = this.bind(this, this.onMessage);
        this.webSocket.onerror = this.bind(this, this.onError);
    };
    
    /* Event that is called when your browser connected to the server... */
    this.onOpen = function(e)
    {
        /* Add a notice to the chat window to inform the user :-) */
        this.addChatMessage('notice', '* Welcome to the PHP WebSockets server demo.<br>You can start chatting with other people now.<br>You can change your nickname by typing "/nick [new_nickname]".');
        /* Update connection status */
        this.connected = true;
    };
    
    /* Event that is called when your browser has been disconnectedt from
     * the server. This event will be called when your browser tried to connect
     * but failed because it is using an old version of the protocol... or the server
     * is just down. */
    this.onClose = function(e)
    {
        /* If we were connected, the server went down or the connection timed out. */
        if (this.connected)
        {
            this.addChatMessage('notice', '* You have left the chatroom');
        }
        else /* If we were never connected, the server is down our your browser has the wrong protocol version */
        {
            this.addChatMessage('notice', '* Welcome to the PHP WebSockets server demo.<br>Your browser could not connect to the WebSocket server. This might be because the server is down or because your browser is using an old version of the protocol. This version of the protocol is only supported by the Chromium and Firefox nightly builds.'); 
        }
    
        /* Update connection status */
        this.connected = false;
    };
    
    /* This event will be called when the browser recieves a message from the server. */
    this.onMessage = function(e)
    {
        /* Split the newly recieved message by spaces to extract the command */
        parts = e.data.split(' ');
    
        /* Handle the command appropriately */
        switch(parts[0])
        {
            case 'MESSAGE': /* This would be a normal chatmessage */
                /* The second part is the nickname of the person that has send the message 
                 * the would be the message so we can merge that part again */
                message = parts.slice(2).join(' ');
                this.addChatMessage('message', '&lt; ' + parts[1] + '&gt; ' + message);
                break;
            case 'CONNECT': /* A new user has joined the conversation. YEEY! */
                this.addChatMessage('notice', '* ' + parts[1] + ' has joined the chatroom.');
                break;
            case 'DISCONNECT': /* Someone has left :-(, tell everbody */
                this.addChatMessage('notice', '* ' + parts[1] + ' has left the chatroom.');
                break;
            case 'NICKCHANGE': /* Someone changed his/hers nickname, let the room know! */
                this.addChatMessage('notice', '* ' + parts[1] + ' is now known as ' + parts [2]);
                break;
            case 'USERLIST': /* We recieved an updated userslist. */
                this.updateUserList(parts[1]);
                break;
           case 'NICKINUSE': /* You tried to change your nick to one that is already in use */
                this.addChatMessage('notice', '* This nickname is already in use');
                break;
        }
    };
    
    /* This event will be raised when an error has accured on the WebSocket */
    this.onError = function(e)
    {
        this.addChatMessage('notice', '** An error has accured');
    };
    
    /* Use this method if you want to send a message to the chatroom */
    this.postMessage = function(message)
    {
        this.webSocket.send('MESSAGE ' + message);
    };
    
    /* Use this method if you want to change your nickname */
    this.changeNick = function(newNick)
    {
        this.webSocket.send('NICKCHANGE ' + newNick);
    };
    
    /* Adds a new message to the chatWindow. There are currently two types;
     * notices and message. A message is a normal chatmessage, a notice is
     * a event that has accured. */
    this.addChatMessage = function(type, message)
    {
        /* Create a new p element */
        newp = document.createElement('p');
        /* Set the correct css class which is the same as the type. Suprise! */
        newp.className = type;
        /* Content would be the message */
        newp.innerHTML = message;
        /* Append to the chatwindows */
        this.chatWindow.appendChild(newp);
    
        /* Scroll to the bottom so the new message is visible */
        this.chatWindow.scrollTop = this.chatWindow.scrollHeight - 400;
    };
    
    /* This method takes a comma separeted list of users and will turn
     * it into a userlist in the userList HTMLElement which happens to
     * to be a UL */
    this.updateUserList = function(userList)
    {
        /* Split the comma separeted list */
        var users = userList.split(',');
        /* Clear the current userlist */
        this.userList.innerHTML = '';
        
        /* Loop through the users and add them to the new userlist */
        for (var user in users)
        {
            /* Create a new li element for each user */
            newli = document.createElement('li');
            /* Put the nickname as content */
            newli.innerHTML = users[user];
            /* And append to the userlist */
            this.userList.appendChild(newli);
        }
    };
    
    /* Used for scoping 'this' */
    this.bind = function (scope, fn) {
        return function () {
            fn.apply(scope, arguments);
       };
    };
};