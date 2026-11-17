import json, random, sys
random.seed(int(sys.argv[1]))
KEYS=["name","alignment","classes","races","tblp","tblpLines","power","type","value","powerLines","powB","label","resChance","innates","consumables","count","armour","pac","nac","locations","head","hits","ac","resist","magic","reductions","numerical","colourResist","text","fraction","immunities","notes","character","version","futureField","spells","ltm","show","points","social","professional","political","combatWary","theme","blue","Lavender","watermark","fire","Mind","layout","poolPrincess","brimful","INNATELYGIFTED","doll","snowflake","tblpRows","pools","torso","ghost","poolsShow","battlemaster","castingStam","immunitiesCompact","consumablesShow","innatesSolo",""]
def val(d=0):
    r=random.random()
    if d>3 or r<0.4: return random.choice([None,True,False,0,-1,7,999999,3.5,1e308,"","GL","gl","Mana","1/2","x"*200,"‮<script>","12",[],{}])
    if r<0.65: return [val(d+1) for _ in range(random.randint(0,(20 if d==0 else 4)))]
    return {random.choice(KEYS):val(d+1) for _ in range(random.randint(0,(10 if d==0 else 4)))}
out=[]
for i in range(300):
    v={k:val() for k in random.sample(KEYS,random.randint(0,len(KEYS)))}
    if random.random()<0.5: v={"app":"battleboard","version":random.choice([1,2,99,"x",None]),"character":v}
    if random.random()<0.05: v=val()
    out.append(v)
json.dump(out,open("cases.json","w"))
