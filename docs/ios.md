# iPhone / iPad

Firefox and Chrome for iOS don't support browser extensions, so the extension can't run there.
(Only Safari has extensions on iOS, and they must be distributed through the App Store.) There are
two ways to create shop addresses on iOS instead.

## 1. In the Roundcube settings (no setup)

Open the webmail, go to **Settings → Preferences → Shop address API**, and use **New shop address**
at the top:

1. Enter the shop name or its website (`gardenshop.example` becomes `gardenshop`).
2. Tap **Create address**, then **Copy**.
3. Switch back to the shop and paste the address into the order form.

**Show existing** lists the addresses you already have for that shop.

Tip: in Firefox, add the settings page to the home screen (*Share → Add to Home Screen*) to get
there with one tap.

## 2. iOS Shortcut from the share sheet

With a Shortcut, the address can be created directly from the shop's page: in Firefox tap
**Share → Shop address**. The Shortcut sends the page address to the REST API, which derives the shop
name from it (`https://checkout.gardenshop.example/…` → `gardenshop`), and copies the new address to the clipboard.

Shortcuts can't rotate tokens, so this needs a **token without rotation**:

* The admin enables them with `$config['identity_api_static_tokens'] = true;`.
* In the Roundcube settings, enter a device name (e.g. "iPhone Shortcut"), tick **Without automatic
  renewal** and save. Copy the token that is shown.

Such a token doesn't expire. It can only create, list and delete your shop addresses, but revoke it
immediately if the device gets lost.

### Create the Shortcut

In the **Shortcuts** app, tap **+** and add these actions:

1. **Receive** *URLs* **from** *Share Sheet*. Under "If there's no input", choose **Ask For** *Text*
   with the prompt "Shop".
   (Tap the ⓘ icon and enable **Show in Share Sheet** if it isn't on yet.)
2. **Get Contents of URL**
   * URL: the API URL from the Roundcube settings followed by `v1/identities`, e.g.
     `https://webmail.example.org/api/identity/v1/identities`
   * Method: **POST**
   * Headers: `Authorization` = `Bearer <your token>`
   * Request Body: **JSON**, one field: key `shop`, type *Text*, value = the variable *Shortcut Input*.
     (The API accepts a website address as shop and derives the shop name from it.)
3. **Get Dictionary Value** for key `email` in *Contents of URL*.
4. **If** *Dictionary Value* **has any value**
   * **Copy to Clipboard**: *Dictionary Value*
   * **Show Notification**: "Copied: *Dictionary Value*"
5. **Otherwise**
   * **Get Dictionary Value** for key `detail` in *Contents of URL*
   * **Show Alert**: *Dictionary Value* (the server's error message)
6. **End If**

Name the Shortcut e.g. "Shop address".

### Use it

On the shop's page in Firefox, tap **Share → Shop address**. The notification shows the new address,
and it's on the clipboard; paste it into the e-mail field. Started from the home screen or widget,
the Shortcut asks for the shop name instead.

To reuse an existing address, open the Roundcube settings (see above) and tap **Show existing**. The
API can list them too (`GET …/v1/identities?shop=gardenshop`), but a Shortcut that lets you pick one
takes more steps.

### Troubleshooting

* **"no token"** / **"invalid token"** (unauthorized): wrong or revoked token, the token isn't one
  without renewal and has expired, the admin disabled tokens without renewal (they then expire like
  normal tokens), or the web server drops the `Authorization` header (Apache: `CGIPassAuth On`).
* **"rate limit exceeded"** / **"identity limit reached"**: the admin's limits for new addresses were
  reached.
* **No notification, nothing copied**: add a **Quick Look** action for *Contents of URL* after step
  2 to see the raw response.
