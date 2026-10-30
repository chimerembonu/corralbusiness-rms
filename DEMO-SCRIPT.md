# CorralBusiness – RMS · 5-minute demo script

**Before the owner arrives (10 minutes):**

1. On the Mac, open Terminal and run `cd /Users/Chimere/Downloads/corralbusiness-rms && composer run rms`. Leave that window open (it runs printing and live kitchen updates).
2. Open Chrome with three windows side by side (or three tabs):
   - Window A: http://corralbusiness-rms.test (the start screen)
   - Window B: http://corralbusiness-rms.test/kitchen (sign in with PIN **3001**, Nana the cook)
   - Window C: http://corralbusiness-rms.test/admin (sign in with your email and password)
3. If you want a clean slate, run `php artisan rms:demo --fresh` first (it rebuilds the demo restaurant and prints every PIN).
4. Optional: in Window C go to **Settings → Printers**, add a "Browser print" printer that prints receipts, and click **Test print** so a receipt pops up during the demo. Skip this if you would rather not have print dialogs.

**Demo PINs:** Owner **1234** · Akosua (Manager) **2468** · Kofi (Cashier) **1001** · Yaw (Waiter) **2001** · Nana (Kitchen) **3001**

---

## The demo (5 minutes)

**Minute 1 – "This is the till."**

1. In Window A click **Till (POS)**. Show the PIN keypad. Enter **1001** (Kofi the cashier).
2. Tap **Dine-in**. Point out the 12 tables, free ones green. Tap **T3**.
3. Tap **Rice Dishes → Jollof Rice**. Show the popup: size, "Choose a protein" is required, "Extras" optional. Pick **Large**, **Chicken**, **Hot**, tap **Add to order**.
4. Tap **Soft Drinks & Juices → Sobolo**. It goes straight on the order. Tap **+** once to make it 2.

**Minute 2 – "Everything is taxed correctly, automatically."**

5. Point at the order panel: Subtotal, then **NHIL 2.5%**, **GETFund Levy 2.5%**, **VAT 15%**, then Total. Say: "These are the 2026 GRA rates and your accountant can change them in Settings. Every receipt shows each one."
6. Tap **Send to kitchen**.

**Minute 3 – "The kitchen sees it instantly."**

7. Switch to Window B. The ticket is there: table T3, 1× Jollof Rice (Large) + Chicken + Hot, 2× Sobolo, with a timer. Explain the colours: green, amber after 5 minutes, red after 10.
8. Tap **Start**, then **Done** (or press **1** on the keyboard twice). Show the "Recently done" strip and tap **Recall** to bring it back.

**Minute 4 – "Taking the money."**

9. Back in Window A, tap **Pay GH₵ …**. Show the keypad and the quick-amount buttons. Tap **200.00**. The change is calculated live. Tap **Complete payment**.
10. The receipt appears, sized for an 80 mm thermal printer, with the restaurant name, each tax line, cash received and change. Press **Print** (or Escape to close the print dialog). Say: "With a real printer this comes out automatically."

**Minute 5 – "You always know how the day went."**

11. Tap **New order**, then **Today's sales** at the top right. Show gross, net, tax collected, by payment method, top items, and the list of orders.
12. Switch to Window C (back office). Click **Menu items**: show the jollof prices, sizes, the **Mark sold out** button. Click **Printers** and **Ingredients** briefly. Say: "You run this yourself, no technician needed. It all works without internet, on a small computer in the restaurant."

**Close:** "Go-live is 13 November. Next we add your real menu, your printers and your staff list."

---

## If something goes wrong

- **Kitchen screen says "Polling" instead of "Live":** fine, it still updates every 4 seconds. The Terminal window with `composer run rms` is probably closed.
- **A "Printer problems" red bar appears:** click **Retry all**. If it persists, the printer is off or the queue worker (same Terminal window) is not running.
- **Wrong PIN five times:** that till is locked for 60 seconds. Use another window or wait.
- **Anything else:** reload the page. Orders are saved on every tap, nothing is lost.
